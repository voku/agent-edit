<?php

declare(strict_types=1);

namespace voku\AgentEdit\Apply;

use ParseError;

/**
 * Deterministic answer to "does this edit change an API other files may depend on?".
 *
 * A direct edit carries no cross-file evidence (no callers, no blockers), so the one class of change that needs such
 * evidence is detected from the token stream instead: removing or renaming a class, function or non-private method,
 * or changing its signature. Bodies, private methods, properties and additions are never reported. A reported change
 * is not an error in the code, it is a change whose callers nobody has looked at yet.
 */
final class SignatureGuard
{
    private const MODIFIERS = [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_ABSTRACT, T_FINAL, T_READONLY];

    /**
     * @return list<string> human-readable API changes between the two sources; empty when none or when either side does not parse
     */
    public function changes(string $before, string $after): array
    {
        $old = $this->declarations($before);
        $new = $this->declarations($after);
        if ($old === null || $new === null) {
            return [];
        }

        $changes = [];
        foreach ($old as $key => $signature) {
            if (!array_key_exists($key, $new)) {
                $changes[] = $this->describe($key) . ' was removed or renamed';
                continue;
            }
            if ($new[$key] !== $signature) {
                $changes[] = $this->describe($key) . ' signature changed from `' . $signature . '` to `' . $new[$key] . '`';
            }
        }

        return $changes;
    }

    /** @return array<string, string>|null declaration key => normalized signature */
    public function declarations(string $source): ?array
    {
        try {
            $tokens = token_get_all($source, TOKEN_PARSE);
        } catch (ParseError) {
            return null;
        }

        $declarations = [];
        $namespace = '';
        /** @var list<array{name: string, depth: int}> $classes */
        $classes = [];
        $depth = 0;
        $pendingClass = null;
        $count = count($tokens);

        for ($index = 0; $index < $count; ++$index) {
            $token = $tokens[$index];
            if (!is_array($token)) {
                if ($token === '{') {
                    ++$depth;
                    if ($pendingClass !== null) {
                        $classes[] = ['name' => $pendingClass, 'depth' => $depth];
                        $pendingClass = null;
                    }
                } elseif ($token === '}') {
                    if ($classes !== [] && $classes[array_key_last($classes)]['depth'] === $depth) {
                        array_pop($classes);
                    }
                    --$depth;
                }
                continue;
            }

            [$id, $text] = $token;
            if ($id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
                ++$depth;
                continue;
            }
            if ($id === T_NAMESPACE && !$this->nextIs($tokens, $index, T_NS_SEPARATOR)) {
                $namespace = $this->readName($tokens, $index + 1);
                continue;
            }
            if (in_array($id, [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
                $previous = $this->previousSignificant($tokens, $index);
                if ($previous === T_DOUBLE_COLON || $previous === T_NEW) {
                    continue;
                }
                $name = $this->nextString($tokens, $index);
                if ($name === null) {
                    continue;
                }
                $fqn = ($namespace === '' ? '' : $namespace . '\\') . $name;
                // Modifiers, `extends` and `implements` are part of what other files rely on.
                $declarations['class:' . $fqn] = $this->signature($tokens, $index)[1];
                $pendingClass = $fqn;
                continue;
            }
            if ($id !== T_FUNCTION) {
                continue;
            }

            $name = $this->functionName($tokens, $index);
            if ($name === null) {
                continue;
            }
            $inClassBody = $classes !== [] && $classes[array_key_last($classes)]['depth'] === $depth;
            if ($classes !== [] && !$inClassBody) {
                continue;
            }
            if ($classes === [] && $depth > ($namespace !== '' && $this->bracedNamespace($tokens) ? 1 : 0)) {
                continue;
            }

            [$modifiers, $signature] = $this->signature($tokens, $index);
            if (in_array('private', $modifiers, true)) {
                continue;
            }
            $owner = $inClassBody ? $classes[array_key_last($classes)]['name'] . '::' : ($namespace === '' ? '' : $namespace . '\\');
            $declarations[($inClassBody ? 'method:' : 'function:') . $owner . $name] = $signature;
        }

        return $declarations;
    }

    private function describe(string $key): string
    {
        [$kind, $name] = explode(':', $key, 2);

        return $kind . ' `' . $name . '`';
    }

    /** @param list<array{int, string, int}|string> $tokens */
    private function bracedNamespace(array $tokens): bool
    {
        foreach ($tokens as $index => $token) {
            if (is_array($token) && $token[0] === T_NAMESPACE && !$this->nextIs($tokens, $index, T_NS_SEPARATOR)) {
                for ($probe = $index + 1; isset($tokens[$probe]); ++$probe) {
                    if ($tokens[$probe] === '{') {
                        return true;
                    }
                    if ($tokens[$probe] === ';') {
                        return false;
                    }
                }
            }
        }

        return false;
    }

    /** @param array<int, array{int, string, int}|string> $tokens */
    private function readName(array $tokens, int $from): string
    {
        $name = '';
        for ($index = $from, $count = count($tokens); $index < $count; ++$index) {
            $token = $tokens[$index];
            if (is_array($token) && in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NS_SEPARATOR], true)) {
                $name .= $token[1];
                continue;
            }
            if (is_array($token) && $token[0] === T_WHITESPACE && $name === '') {
                continue;
            }
            break;
        }

        return $name;
    }

    /** @param array<int, array{int, string, int}|string> $tokens */
    private function nextIs(array $tokens, int $index, int $type): bool
    {
        for ($probe = $index + 1, $count = count($tokens); $probe < $count; ++$probe) {
            $token = $tokens[$probe];
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return is_array($token) && $token[0] === $type;
        }

        return false;
    }

    /** @param array<int, array{int, string, int}|string> $tokens */
    private function previousSignificant(array $tokens, int $index): ?int
    {
        for ($probe = $index - 1; $probe >= 0; --$probe) {
            $token = $tokens[$probe];
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return is_array($token) ? $token[0] : null;
        }

        return null;
    }

    /** @param array<int, array{int, string, int}|string> $tokens */
    private function nextString(array $tokens, int $index): ?string
    {
        for ($probe = $index + 1, $count = count($tokens); $probe < $count; ++$probe) {
            $token = $tokens[$probe];
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return is_array($token) && $token[0] === T_STRING ? $token[1] : null;
        }

        return null;
    }

    /** @param array<int, array{int, string, int}|string> $tokens */
    private function functionName(array $tokens, int $index): ?string
    {
        for ($probe = $index + 1, $count = count($tokens); $probe < $count; ++$probe) {
            $token = $tokens[$probe];
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            // PHP 8.1+ reports the by-reference marker as a token of its own, older versions as the plain character.
            if ($token === '&' || (is_array($token) && $token[1] === '&')) {
                continue;
            }

            return is_array($token) && $token[0] === T_STRING ? $token[1] : null;
        }

        return null;
    }

    /**
     * @param array<int, array{int, string, int}|string> $tokens
     * @return array{list<string>, string}
     */
    private function signature(array $tokens, int $functionIndex): array
    {
        $parts = [];
        $modifiers = [];
        for ($probe = $functionIndex - 1; $probe >= 0; --$probe) {
            $token = $tokens[$probe];
            if (is_array($token) && $token[0] === T_WHITESPACE) {
                continue;
            }
            if (is_array($token) && in_array($token[0], self::MODIFIERS, true)) {
                array_unshift($modifiers, strtolower($token[1]));
                continue;
            }
            break;
        }

        $parenthesis = 0;
        for ($index = $functionIndex, $count = count($tokens); $index < $count; ++$index) {
            $token = $tokens[$index];
            $text = is_array($token) ? $token[1] : $token;
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            if ($text === '(') {
                ++$parenthesis;
            } elseif ($text === ')') {
                --$parenthesis;
            }
            if ($parenthesis === 0 && ($text === '{' || $text === ';')) {
                break;
            }
            $parts[] = $text;
        }

        // A trailing comma before the closing parenthesis is formatting, not a different signature.
        $normalized = [];
        foreach ($parts as $position => $part) {
            if ($part === ',' && ($parts[$position + 1] ?? null) === ')') {
                continue;
            }
            $normalized[] = $part;
        }

        return [$modifiers, trim(implode(' ', $modifiers) . ' ' . implode(' ', $normalized))];
    }
}
