<?php

declare(strict_types=1);

namespace voku\AgentEdit\Plan;

use RuntimeException;

/**
 * Fail-closed decoder for `class_scaffold_plan@1.0`: one new PHP file that declares exactly the named class-like type. The
 * plan's own `file_creation` edit carries the whole file; this decoder proves the text is only what the plan says it is.
 */
final readonly class ClassScaffoldPlanDocument
{
    public const PLAN_TYPE = 'class_scaffold_plan';
    private const NAME = '[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*';

    private function __construct(
        public string $targetId,
        public string $fqn,
        public string $path,
        public string $content,
        public RenamePlanProvenanceEvidence $provenance,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        if (($data['type'] ?? null) !== self::PLAN_TYPE || ($data['contract_version'] ?? null) !== '1.0') {
            throw new RuntimeException('Unsupported agent-map class scaffold plan contract.');
        }
        if (($data['status'] ?? null) === 'review_required') {
            throw PlanRefusal::because('Class scaffold plan requires explicit review; no source was changed.', $data);
        }
        if (($data['status'] ?? null) !== 'safe') {
            throw PlanRefusal::because('Class scaffold plan is not safe; no source was changed.', $data);
        }
        foreach (['blind_spots', 'stale_evidence', 'blockers'] as $field) {
            $value = $data[$field] ?? null;
            if (!is_array($value) || !array_is_list($value)) {
                throw new RuntimeException('Class scaffold plan requires ' . $field . ' list evidence.');
            }
            if ($value !== []) {
                throw PlanRefusal::because('Class scaffold plan requires empty ' . $field . ' evidence.', $data);
            }
        }
        if (isset($data['moves']) && (!is_array($data['moves']) || $data['moves'] !== [])) {
            throw new RuntimeException('Class scaffold plan may not publish file moves.');
        }

        $fqn = self::string($data, 'fqn');
        if (preg_match('/\A' . self::NAME . '(?:\\\\' . self::NAME . ')*\z/D', $fqn) !== 1) {
            throw new RuntimeException('Class scaffold fqn must be an exact, unprefixed class identity.');
        }
        if (self::string($data, 'target_id') !== 'class:' . $fqn) {
            throw new RuntimeException('Class scaffold target identity must name the scaffolded class.');
        }
        $path = self::safePath(self::string($data, 'destination_path'));

        $rawProvenance = $data['provenance'] ?? null;
        if (!is_array($rawProvenance)) {
            throw new RuntimeException('Class scaffold plan requires typed provenance evidence.');
        }
        $edits = $data['edits'] ?? null;
        if (!is_array($edits) || !array_is_list($edits) || count($edits) !== 1 || !is_array($edits[0])) {
            throw new RuntimeException('Class scaffold plan requires exactly one file creation edit.');
        }
        $edit = $edits[0];
        if (($edit['role'] ?? null) !== 'file_creation'
            || ($edit['resolution'] ?? null) !== 'scaffold_class'
            || ($edit['symbol_id'] ?? null) !== $fqn
            || ($edit['path'] ?? null) !== $path
        ) {
            throw new RuntimeException('Class scaffold edit is not bound to the exact class identity and destination path.');
        }
        if (($edit['source_sha256'] ?? null) !== '' || ($edit['expected'] ?? null) !== ''
            || ($edit['start_file_pos'] ?? null) !== 0 || ($edit['end_file_pos'] ?? null) !== 0
        ) {
            throw new RuntimeException('Class scaffold edit must create a file: it may not claim to replace existing bytes.');
        }
        $content = $edit['replacement'] ?? null;
        if (!is_string($content) || $content === '') {
            throw new RuntimeException('Class scaffold edit requires the complete file content.');
        }
        self::assertDeclaresOnly($content, $fqn);

        return new self('class:' . $fqn, $fqn, $path, $content, RenamePlanProvenanceEvidence::fromArray($rawProvenance));
    }

    /**
     * The file must be a PHP open tag, optional `declare`, an optional `namespace`, `use` imports and exactly one class,
     * interface, trait or enum with the planned name. Nothing executable may sit at the top level.
     */
    private static function assertDeclaresOnly(string $content, string $fqn): void
    {
        if (!str_starts_with($content, "<?php\n")) {
            throw new RuntimeException('Class scaffold content must start with a PHP open tag.');
        }
        try {
            $tokens = token_get_all($content, TOKEN_PARSE);
        } catch (\ParseError $error) {
            throw new RuntimeException('Class scaffold content does not parse: ' . $error->getMessage(), 0, $error);
        }

        $trivia = [T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT];
        $modifiers = [T_FINAL, T_ABSTRACT, T_READONLY];
        $kinds = [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM];
        $depth = 0;
        $namespace = '';
        $declared = [];
        $statement = null;
        $count = count($tokens);
        for ($index = 0; $index < $count; ++$index) {
            $token = $tokens[$index];
            if (is_string($token)) {
                if ($token === '{') {
                    ++$depth;
                } elseif ($token === '}') {
                    --$depth;
                    if ($depth === 0 && $statement === 'type') {
                        $statement = null;
                    }
                } elseif ($token === ';' && $depth === 0 && $statement !== 'type') {
                    $statement = null;
                }
                continue;
            }
            if ($token[0] === T_CURLY_OPEN || $token[0] === T_DOLLAR_OPEN_CURLY_BRACES) {
                ++$depth;
                continue;
            }
            if ($depth > 0 || in_array($token[0], $trivia, true)) {
                continue;
            }
            if ($statement === null) {
                $statement = match (true) {
                    $token[0] === T_DECLARE => 'declare',
                    $token[0] === T_NAMESPACE => 'namespace',
                    $token[0] === T_USE => 'use',
                    in_array($token[0], $modifiers, true) || in_array($token[0], $kinds, true) => 'type',
                    default => throw new RuntimeException('Class scaffold content may only declare one type; found top-level code: ' . trim($token[1]) . '.'),
                };
            }
            if ($statement === 'namespace' && $token[0] !== T_NAMESPACE) {
                $namespace .= $token[1];
            }
            if ($statement === 'type' && in_array($token[0], $kinds, true)) {
                for ($next = $index + 1; $next < $count; ++$next) {
                    $candidate = $tokens[$next];
                    if (is_array($candidate) && in_array($candidate[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                        continue;
                    }
                    $declared[] = is_array($candidate) && $candidate[0] === T_STRING ? $candidate[1] : '';
                    break;
                }
            }
        }

        $separator = strrpos($fqn, '\\');
        $expectedNamespace = $separator === false ? '' : substr($fqn, 0, $separator);
        $expectedName = $separator === false ? $fqn : substr($fqn, $separator + 1);
        if ($declared !== [$expectedName]) {
            throw new RuntimeException('Class scaffold content must declare exactly the type ' . $expectedName . '.');
        }
        if ($namespace !== $expectedNamespace) {
            throw new RuntimeException('Class scaffold content must declare namespace ' . ($expectedNamespace === '' ? '(global)' : $expectedNamespace) . '.');
        }
    }

    private static function safePath(string $path): string
    {
        if (str_contains($path, "\0") || str_contains($path, '\\') || str_starts_with($path, '/') || preg_match('~^[A-Za-z]:~', $path) === 1) {
            throw new RuntimeException('Class scaffold destination must be a project-relative path.');
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new RuntimeException('Class scaffold destination is not a canonical relative path: ' . $path);
            }
        }
        if (!str_ends_with($path, '.php')) {
            throw new RuntimeException('Class scaffold destination must be a .php file.');
        }

        return $path;
    }

    /** @param array<string, mixed> $data */
    private static function string(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new RuntimeException('Class scaffold plan requires non-empty string ' . $key . '.');
        }

        return $value;
    }
}
