<?php

declare(strict_types=1);

namespace voku\AgentEdit\Symbol;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\ParserFactory;
use PhpParser\Token;

/**
 * Parsed view of one PHP file: its declarations, top-level imports and tokens. Resolves the symbol strings an edit
 * request uses and, when that fails, says which symbols exist so the request can be corrected in one step.
 */
final readonly class SourceSymbols
{
    /**
     * @param list<SourceSymbol> $symbols
     * @param list<Stmt\Use_|Stmt\GroupUse> $imports namespace-level `use` statements (also grouped ones), in source order
     * @param list<Stmt\Namespace_> $namespaces
     * @param list<Token> $tokens
     */
    private function __construct(
        public array $symbols,
        public array $imports,
        public array $namespaces,
        public array $tokens,
    ) {
    }

    public static function parse(string $content, string $path): self
    {
        $parser = (new ParserFactory())->createForHostVersion();
        try {
            $statements = $parser->parse($content) ?? [];
        } catch (Error $error) {
            throw new SymbolEditException(sprintf(
                '%s is not valid PHP (%s), so its symbols cannot be resolved. Use replace_text for this file or fix the syntax first.',
                $path,
                $error->getMessage(),
            ));
        }

        $symbols = [];
        $imports = [];
        $namespaces = [];
        foreach ($statements as $statement) {
            if ($statement instanceof Stmt\Namespace_) {
                $namespaces[] = $statement;
                $namespace = $statement->name?->toString() ?? '';
                foreach ($statement->stmts as $inner) {
                    self::collect($inner, $namespace, $symbols, $imports);
                }
                continue;
            }
            self::collect($statement, '', $symbols, $imports);
        }

        return new self($symbols, $imports, $namespaces, array_values($parser->getTokens()));
    }

    /**
     * Resolves `Class`, `Class::method()`, `Class::method`, `Class::$property`, `Class::CONSTANT`, `Enum::CASE` or
     * `function()`; the class part may be the short or the fully qualified name.
     *
     * @param list<string> $kinds accepted symbol kinds
     */
    public function resolve(string $reference, string $path, array $kinds): SourceSymbol
    {
        $query = ltrim(trim($reference), '\\');
        $wantsCall = str_ends_with($query, '()');
        if ($wantsCall) {
            $query = substr($query, 0, -2);
        }

        if (str_contains($query, '::')) {
            [$ownerName, $member] = explode('::', $query, 2);
            $ownerName = ltrim($ownerName, '\\');
            $matches = array_values(array_filter(
                $this->symbols,
                static fn (SourceSymbol $symbol): bool => $symbol->isMember()
                    && self::sameClass($symbol->owner, $ownerName)
                    && self::sameMember($symbol, $member, $wantsCall),
            ));
        } else {
            $matches = array_values(array_filter(
                $this->symbols,
                static fn (SourceSymbol $symbol): bool => !$symbol->isMember()
                    && (!$wantsCall || $symbol->kind === 'function')
                    && self::sameClass($symbol->fqn, $query),
            ));
        }

        $accepted = array_values(array_filter($matches, static fn (SourceSymbol $symbol): bool => in_array($symbol->kind, $kinds, true)));
        if (count($accepted) === 1) {
            return $accepted[0];
        }
        if (count($accepted) > 1) {
            throw new SymbolEditException(sprintf(
                'Symbol `%s` is ambiguous in %s: %s. Use the fully qualified form or add `()` / `$` to pick one.',
                $reference,
                $path,
                implode(', ', array_map(static fn (SourceSymbol $symbol): string => $symbol->display(), $accepted)),
            ));
        }
        if ($matches !== []) {
            throw new SymbolEditException(sprintf(
                'Symbol `%s` in %s is a %s; this operation needs a %s.',
                $reference,
                $path,
                $matches[0]->kind,
                implode(' or ', $kinds),
            ));
        }

        throw new SymbolEditException(sprintf(
            'Symbol `%s` was not found in %s. Declared there: %s.',
            $reference,
            $path,
            $this->outline(),
        ));
    }

    /** A compact list of every addressable declaration, used in error messages. */
    public function outline(int $limit = 40): string
    {
        if ($this->symbols === []) {
            return 'no class, function or member declarations';
        }
        $names = array_map(static fn (SourceSymbol $symbol): string => $symbol->display(), $this->symbols);
        $shown = array_slice($names, 0, $limit);

        return implode(', ', $shown) . (count($names) > $limit ? sprintf(' (+%d more)', count($names) - $limit) : '');
    }

    /** @return list<SourceSymbol> members of one class-like, in source order */
    public function membersOf(SourceSymbol $owner): array
    {
        return array_values(array_filter(
            $this->symbols,
            static fn (SourceSymbol $symbol): bool => $symbol->ownerNode === $owner->node,
        ));
    }

    /**
     * Byte offset of the `{` that opens the block closed by the token at `$closingTokenIndex`.
     */
    public function openingBrace(int $closingTokenIndex): int
    {
        $depth = 0;
        for ($index = $closingTokenIndex; $index >= 0; --$index) {
            $token = $this->tokens[$index];
            if ($token->text === '}') {
                ++$depth;
            } elseif ($token->text === '{' || $token->id === T_CURLY_OPEN || $token->id === T_DOLLAR_OPEN_CURLY_BRACES) {
                --$depth;
                if ($depth === 0) {
                    return $token->pos + strlen($token->text) - 1;
                }
            }
        }

        throw new SymbolEditException('Unable to find the opening brace of a declaration body.');
    }

    /**
     * @param list<SourceSymbol> $symbols
     * @param list<Stmt\Use_|Stmt\GroupUse> $imports
     */
    private static function collect(Stmt $statement, string $namespace, array &$symbols, array &$imports): void
    {
        $prefix = $namespace === '' ? '' : $namespace . '\\';
        if ($statement instanceof Stmt\Use_ || $statement instanceof Stmt\GroupUse) {
            $imports[] = $statement;

            return;
        }
        if ($statement instanceof Stmt\Function_) {
            $symbols[] = new SourceSymbol('function', $statement->name->toString(), $prefix . $statement->name->toString(), '', $statement, null);

            return;
        }
        if (!$statement instanceof Stmt\ClassLike || $statement->name === null) {
            return;
        }

        $kind = match (true) {
            $statement instanceof Stmt\Interface_ => 'interface',
            $statement instanceof Stmt\Trait_ => 'trait',
            $statement instanceof Stmt\Enum_ => 'enum',
            default => 'class',
        };
        $owner = $prefix . $statement->name->toString();
        $symbols[] = new SourceSymbol($kind, $statement->name->toString(), $owner, '', $statement, null);

        foreach ($statement->stmts as $member) {
            foreach (self::members($member) as [$memberKind, $name, $count]) {
                $symbols[] = new SourceSymbol($memberKind, $name, $owner . '::' . $name, $owner, $member, $statement, $count);
            }
        }
    }

    /** @return list<array{string, string, int}> kind, name, declarations in the statement */
    private static function members(Node $member): array
    {
        if ($member instanceof Stmt\ClassMethod) {
            return [['method', $member->name->toString(), 1]];
        }
        if ($member instanceof Stmt\Property) {
            $count = count($member->props);

            return array_values(array_map(static fn (Node\PropertyItem $item): array => ['property', $item->name->toString(), $count], $member->props));
        }
        if ($member instanceof Stmt\ClassConst) {
            $count = count($member->consts);

            return array_values(array_map(static fn (Node\Const_ $const): array => ['constant', $const->name->toString(), $count], $member->consts));
        }
        if ($member instanceof Stmt\EnumCase) {
            return [['case', $member->name->toString(), 1]];
        }

        return [];
    }

    private static function sameClass(string $fqn, string $query): bool
    {
        $short = ($position = strrpos($fqn, '\\')) === false ? $fqn : substr($fqn, $position + 1);

        return strcasecmp($fqn, $query) === 0 || strcasecmp($short, $query) === 0;
    }

    private static function sameMember(SourceSymbol $symbol, string $member, bool $wantsCall): bool
    {
        if (str_starts_with($member, '$')) {
            return $symbol->kind === 'property' && $symbol->name === substr($member, 1);
        }
        if ($wantsCall) {
            return $symbol->kind === 'method' && strcasecmp($symbol->name, $member) === 0;
        }

        return match ($symbol->kind) {
            'method' => strcasecmp($symbol->name, $member) === 0,
            'constant', 'case' => $symbol->name === $member,
            default => false,
        };
    }
}
