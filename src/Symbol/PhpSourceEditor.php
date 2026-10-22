<?php

declare(strict_types=1);

namespace voku\AgentEdit\Symbol;

use PhpParser\Node\Stmt;

/**
 * Applies one symbol-addressed operation of a `source_edit_plan` (replace_symbol, replace_body, insert_member,
 * remove_symbol, add_use) to the content of one file and returns the new content. Pure: it never touches the
 * filesystem. Code written by the agent is re-indented to the target position and uses the file's line ending unless
 * the edit sets `"reindent": false`.
 */
final readonly class PhpSourceEditor
{
    private const MEMBER_KINDS = ['method', 'property', 'constant', 'case'];
    private const CLASS_KINDS = ['class', 'interface', 'trait', 'enum'];

    /**
     * Returns the new content and the one byte range of the original content the operation replaced, so a caller that
     * combines several edits of one file can check them for overlaps (a pure insertion is a zero-length range).
     *
     * @param array<string, mixed> $edit one validated symbol operation of a source_edit_plan (`op` plus its fields)
     * @return array{content: string, summary: string, range: ?array{int, int, string}} range: start, end (exclusive),
     *         replacement; null when the operation changes nothing
     */
    public function apply(string $content, array $edit, string $path): array
    {
        $result = match ($edit['op']) {
            'replace_symbol' => $this->replaceSymbol($content, $edit, $path),
            'replace_body' => $this->replaceBody($content, $edit, $path),
            'insert_member' => $this->insertMember($content, $edit, $path),
            'remove_symbol' => $this->removeSymbol($content, $edit, $path),
            'add_use' => $this->addUse($content, $edit, $path),
            default => throw new SymbolEditException('Operation ' . (string) $edit['op'] . ' does not edit file content.'),
        };
        $range = $result['range'];

        return [
            'content' => $range === null ? $content : substr($content, 0, $range[0]) . $range[2] . substr($content, $range[1]),
            'summary' => $result['summary'],
            'range' => $range,
        ];
    }

    /**
     * @param array<string, mixed> $edit
     * @return array{range: ?array{int, int, string}, summary: string}
     */
    private function replaceSymbol(string $content, array $edit, string $path): array
    {
        $symbols = SourceSymbols::parse($content, $path);
        $symbol = $symbols->resolve((string) $edit['symbol'], $path, [...self::MEMBER_KINDS, ...self::CLASS_KINDS, 'function']);
        $this->assertSingleDeclaration($symbol, $path, 'replace_symbol');
        $code = (string) $edit['code'];
        if (trim($code) === '') {
            throw new SymbolEditException('replace_symbol needs non-empty `code`; use remove_symbol to delete a declaration.');
        }

        $layout = new TextLayout($content);
        $start = $symbol->docStartPos();
        $indent = $layout->indentAt($start);
        $eol = $layout->eol();
        if (($edit['reindent'] ?? true) === false) {
            $replacement = $this->code($code, $indent, $eol, $edit, false, $layout->indentUnit());
            $start = str_starts_with(ltrim($code), '/**') ? $start : $symbol->startPos();
        } else {
            // The doc comment and the attributes are kept unless the new code brings its own: omitted means unchanged.
            $head = $this->splitHead(TextLayout::reindent($code, '', "\n"));
            $doc = $symbol->node->getDocComment();
            $attributes = $this->attributeText($content, $symbol);
            $parts = array_values(array_filter([
                // A kept doc comment's first line starts after the indentation, its other lines include it.
                $head['doc'] ?? ($doc === null ? null : TextLayout::reindent($layout->indentAt($doc->getStartFilePos()) . $doc->getText(), '', "\n", true)),
                $head['attributes'] ?? $attributes,
                $head['declaration'],
            ], static fn (?string $part): bool => $part !== null && $part !== ''));
            $replacement = implode($eol . $indent, array_map(
                static fn (string $part): string => TextLayout::reindent($part, $indent, $eol, false, $layout->indentUnit()),
                $parts,
            ));
        }

        return [
            'range' => [$start, $symbol->endPos() + 1, $replacement],
            'summary' => sprintf('replaced %s (lines %d-%d)', $symbol->display(), $layout->lineNumber($start), $layout->lineNumber($symbol->endPos())),
        ];
    }

    /**
     * Splits agent-written declaration code into its optional leading doc comment, its optional attribute groups and
     * the declaration itself.
     *
     * @return array{doc: ?string, attributes: ?string, declaration: string}
     */
    private function splitHead(string $code): array
    {
        $rest = ltrim($code);
        $doc = null;
        if (str_starts_with($rest, '/**') && ($close = strpos($rest, '*/')) !== false) {
            $doc = substr($rest, 0, $close + 2);
            $rest = ltrim(substr($rest, $close + 2));
        }
        $attributes = [];
        while (str_starts_with($rest, '#[')) {
            $end = $this->attributeGroupEnd($rest);
            $attributes[] = substr($rest, 0, $end + 1);
            $rest = ltrim(substr($rest, $end + 1));
        }

        return ['doc' => $doc, 'attributes' => $attributes === [] ? null : implode("\n", $attributes), 'declaration' => $rest];
    }

    /** Offset of the `]` that closes the attribute group starting at offset 0, skipping quoted strings. */
    private function attributeGroupEnd(string $code): int
    {
        $depth = 0;
        $length = strlen($code);
        for ($index = 0; $index < $length; ++$index) {
            $char = $code[$index];
            if ($char === "'" || $char === '"') {
                for (++$index; $index < $length && $code[$index] !== $char; ++$index) {
                    if ($code[$index] === '\\') {
                        ++$index;
                    }
                }
                continue;
            }
            if ($char === '[') {
                ++$depth;
            } elseif ($char === ']' && --$depth === 0) {
                return $index;
            }
        }

        throw new SymbolEditException('An attribute group in `code` is not closed (missing `]`).');
    }

    /** The symbol's existing attribute groups as written, or null when it has none. */
    private function attributeText(string $content, SourceSymbol $symbol): ?string
    {
        $groups = property_exists($symbol->node, 'attrGroups') ? $symbol->node->attrGroups : [];
        if (!is_array($groups) || $groups === []) {
            return null;
        }
        $first = $groups[0]->getStartFilePos();
        $last = $groups[count($groups) - 1]->getEndFilePos();
        $indent = (new TextLayout($content))->indentAt($first);

        return TextLayout::reindent($indent . substr($content, $first, $last - $first + 1), '', "\n", true);
    }

    /**
     * @param array<string, mixed> $edit
     * @return array{range: ?array{int, int, string}, summary: string}
     */
    private function replaceBody(string $content, array $edit, string $path): array
    {
        $symbols = SourceSymbols::parse($content, $path);
        $symbol = $symbols->resolve((string) $edit['symbol'], $path, ['method', 'function']);
        $node = $symbol->node;
        if (($node instanceof Stmt\ClassMethod || $node instanceof Stmt\Function_) && $node->stmts === null) {
            throw new SymbolEditException(sprintf('%s has no body (abstract or interface method); use replace_symbol.', $symbol->display()));
        }

        $layout = new TextLayout($content);
        $open = $symbols->openingBrace($node->getEndTokenPos());
        $close = $symbol->endPos();
        $indent = $layout->indentAt($symbol->docStartPos());
        $eol = $layout->eol();
        $body = $this->code((string) $edit['code'], $indent . $layout->indentUnit(), $eol, $edit, true, $layout->indentUnit());
        $block = '{' . $eol . ($body === '' ? '' : $body . $eol) . $indent . '}';

        return [
            'range' => [$open, $close + 1, $block],
            'summary' => sprintf('replaced the body of %s (lines %d-%d)', $symbol->display(), $layout->lineNumber($open), $layout->lineNumber($close)),
        ];
    }

    /**
     * @param array<string, mixed> $edit
     * @return array{range: ?array{int, int, string}, summary: string}
     */
    private function insertMember(string $content, array $edit, string $path): array
    {
        $symbols = SourceSymbols::parse($content, $path);
        $owner = $symbols->resolve((string) $edit['symbol'], $path, self::CLASS_KINDS);
        $members = $symbols->membersOf($owner);
        $layout = new TextLayout($content);
        $eol = $layout->eol();
        $memberIndent = $members !== []
            ? $layout->indentAt($members[0]->docStartPos())
            : $layout->indentAt($owner->docStartPos()) . $layout->indentUnit();
        $block = $this->code((string) $edit['code'], $memberIndent, $eol, $edit, true, $layout->indentUnit());
        if (trim($block) === '') {
            throw new SymbolEditException('insert_member needs non-empty `code`.');
        }

        [$anchor, $where] = $this->insertionAnchor($edit, $members, $owner, $symbols, $path);
        $classClose = $owner->endPos();
        $classOpen = $symbols->openingBrace($owner->node->getEndTokenPos());
        if ($anchor === null && $layout->lineNumber($classOpen) === $layout->lineNumber($classClose)) {
            // `class A {}` on one line: open the body.
            $ownerIndent = $layout->indentAt($owner->docStartPos());

            return [
                'range' => [$classOpen + 1, $classClose, $eol . $block . $eol . $ownerIndent],
                'summary' => sprintf('inserted a member into %s %s', $owner->display(), $where),
            ];
        }

        $at = match (true) {
            $anchor === null && $where === 'at the start' => $layout->nextLineStart($classOpen),
            $anchor === null => $layout->lineStart($classClose),
            $where === 'before' => $layout->lineStart($anchor->docStartPos()),
            default => $layout->nextLineStart($anchor->endPos()),
        };
        $before = substr($content, 0, $at);
        $after = substr($content, $at);
        $previousLine = trim((string) preg_replace('/\R$/', '', substr($before, (new TextLayout($before))->lineStart(max(0, strlen($before) - 1)))));
        $nextLine = trim(explode("\n", $after)[0]);
        $prefix = $previousLine === '' || str_ends_with($previousLine, '{') ? '' : $eol;
        $suffix = $nextLine === '' || str_starts_with($nextLine, '}') ? $eol : $eol . $eol;

        return [
            'range' => [$at, $at, $prefix . $block . $suffix],
            'summary' => sprintf('inserted a member into %s %s', $owner->display(), $anchor === null ? $where : $where . ' ' . $anchor->display()),
        ];
    }

    /**
     * @param array<string, mixed> $edit
     * @param list<SourceSymbol> $members
     * @return array{?SourceSymbol, string}
     */
    private function insertionAnchor(array $edit, array $members, SourceSymbol $owner, SourceSymbols $symbols, string $path): array
    {
        $position = $edit['position'] ?? 'end';
        if (isset($edit['after']) || isset($edit['before'])) {
            $relation = isset($edit['after']) ? 'after' : 'before';
            $name = $edit[$relation];
            if (!is_string($name) || $name === '') {
                throw new SymbolEditException('insert_member `' . $relation . '` must name a member, for example "bar()", "$baz" or "QUX".');
            }
            $reference = str_contains($name, '::') ? $name : $owner->fqn . '::' . $name;

            return [$symbols->resolve($reference, $path, self::MEMBER_KINDS), $relation];
        }
        if ($position === 'start') {
            return [null, 'at the start'];
        }
        if ($position !== 'end') {
            throw new SymbolEditException('insert_member `position` must be "start" or "end"; use `after` or `before` to anchor on a member.');
        }

        return [null, 'at the end'];
    }

    /**
     * @param array<string, mixed> $edit
     * @return array{range: ?array{int, int, string}, summary: string}
     */
    private function removeSymbol(string $content, array $edit, string $path): array
    {
        $symbols = SourceSymbols::parse($content, $path);
        $symbol = $symbols->resolve((string) $edit['symbol'], $path, [...self::MEMBER_KINDS, ...self::CLASS_KINDS, 'function']);
        $this->assertSingleDeclaration($symbol, $path, 'remove_symbol');
        $layout = new TextLayout($content);

        $start = $symbol->docStartPos();
        $end = $symbol->endPos() + 1;
        if ($layout->onlyWhitespaceBefore($start)) {
            $start = $layout->lineStart($start);
        }
        $rest = substr($content, $end, $layout->nextLineStart($end - 1) - $end);
        if (trim($rest) === '') {
            $end = $layout->nextLineStart($end - 1);
        }
        // The blank line the removal makes redundant belongs to the same range, so it cannot overlap another edit.
        $collapsed = $this->redundantBlankLine(substr($content, 0, $start) . substr($content, $end), $start);
        if ($collapsed !== null) {
            [$removedStart, $removedLength] = $collapsed;
            if ($removedStart >= $start) {
                $end += $removedLength;
            } else {
                $start = $removedStart;
            }
        }

        return [
            'range' => [$start, $end, ''],
            'summary' => sprintf('removed %s (lines %d-%d); references were not checked', $symbol->display(), $layout->lineNumber($start), $layout->lineNumber($symbol->endPos())),
        ];
    }

    /**
     * After a removal at `$at` (in the already shortened content), the blank line that would leave a doubled blank line
     * or one right inside braces: its offset and length in the shortened content, or null.
     *
     * @return array{int, int}|null
     */
    private function redundantBlankLine(string $content, int $at): ?array
    {
        $layout = new TextLayout($content);
        $previous = $at === 0 ? null : $layout->lineAt($at - 1);
        if ($at >= strlen($content) && $previous !== null && trim($previous) === '') {
            // The removed declaration was the last one in the file: drop the blank line that separated it.
            $previousStart = $layout->lineStart($at - 1);

            return [$previousStart, $at - $previousStart];
        }
        $lineAt = $layout->lineAt(min($at, max(0, strlen($content) - 1)));
        $atLineIsBlank = $at < strlen($content) && trim($lineAt) === '' && $layout->lineStart($at) === $at;

        if ($atLineIsBlank && $previous !== null && (trim($previous) === '' || str_ends_with(rtrim($previous), '{'))) {
            return [$at, $layout->nextLineStart($at) - $at];
        }
        if ($previous !== null && trim($previous) === '' && str_starts_with(ltrim($lineAt), '}')) {
            $previousStart = $layout->lineStart($at - 1);

            return [$previousStart, $at - $previousStart];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $edit
     * @return array{range: ?array{int, int, string}, summary: string}
     */
    private function addUse(string $content, array $edit, string $path): array
    {
        $name = ltrim(trim((string) $edit['name']), '\\');
        $alias = $edit['alias'] ?? null;
        $kind = $edit['kind'] ?? 'class';
        if (preg_match('/^[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*(\\\\[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)*$/', $name) !== 1) {
            throw new SymbolEditException('add_use `name` must be a fully qualified name, for example "App\\Service\\Mailer".');
        }
        if ($alias !== null && (!is_string($alias) || preg_match('/^[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*$/', $alias) !== 1)) {
            throw new SymbolEditException('add_use `alias` must be a plain identifier.');
        }
        if (!in_array($kind, ['class', 'function', 'const'], true)) {
            throw new SymbolEditException('add_use `kind` must be "class", "function" or "const".');
        }

        $symbols = SourceSymbols::parse($content, $path);
        if (count($symbols->namespaces) > 1) {
            throw new SymbolEditException($path . ' declares more than one namespace; add the import with replace_text.');
        }
        $type = ['class' => Stmt\Use_::TYPE_NORMAL, 'function' => Stmt\Use_::TYPE_FUNCTION, 'const' => Stmt\Use_::TYPE_CONSTANT][$kind];
        $shortName = is_string($alias) ? $alias : substr($name, (int) strrpos('\\' . $name, '\\'));
        $sameKind = [];
        foreach ($symbols->imports as $use) {
            $items = $this->importItems($use);
            // A statement belongs to the block of a kind when everything it imports is of that kind (a mixed group
            // `use A\{B, function c}` belongs to none, but its items still count for duplicates and aliases).
            if (array_unique(array_column($items, 0)) === [$type]) {
                $sameKind[] = $use;
            }
            foreach ($items as [$itemType, $existing, $existingAlias]) {
                if ($itemType !== $type) {
                    continue;
                }
                if (strcasecmp($existing, $name) === 0 && strcasecmp($existingAlias, $shortName) === 0) {
                    return ['range' => null, 'summary' => sprintf('%s is already imported', $name)];
                }
                if (strcasecmp($existingAlias, $shortName) === 0) {
                    throw new SymbolEditException(sprintf('%s already imports %s as %s; set a different `alias`.', $path, $existing, $existingAlias));
                }
            }
        }

        $layout = new TextLayout($content);
        $eol = $layout->eol();
        $statement = 'use ' . ($kind === 'class' ? '' : $kind . ' ') . $name . (is_string($alias) ? ' as ' . $alias : '') . ';';

        if ($sameKind !== []) {
            $sorted = $this->importsSorted($sameKind);
            $anchor = $sameKind[count($sameKind) - 1];
            $insertBefore = null;
            if ($sorted) {
                foreach ($sameKind as $use) {
                    if (strcasecmp($this->importItems($use)[0][1], $name) > 0) {
                        $insertBefore = $use;
                        break;
                    }
                }
            }
            $indent = $layout->indentAt($anchor->getStartFilePos());
            $at = $insertBefore !== null ? $layout->lineStart($insertBefore->getStartFilePos()) : $this->lineStartAfterImport($content, $layout, $anchor, $path);

            return [
                'range' => [$at, $at, $indent . $statement . $eol],
                'summary' => sprintf('imported %s', $name),
            ];
        }

        // A kind without imports yet opens its own block after the blocks of the kinds that precede it (PSR-12 and
        // PER-CS: class, function, const). Before them it would sit above the class imports of the file.
        $before = $this->lastImportOfEarlierKind($symbols->imports, $type);
        if ($before !== null) {
            $at = $this->lineStartAfterImport($content, $layout, $before, $path);
            $blankFollows = trim($layout->lineAt($at)) === '';

            return [
                'range' => [$at, $at, $eol . $layout->indentAt($before->getStartFilePos()) . $statement . $eol . ($blankFollows ? '' : $eol)],
                'summary' => sprintf('imported %s', $name),
            ];
        }

        [$at, $text] = $this->firstImport($content, $symbols, $statement, $eol, $path);

        return [
            'range' => [$at, $at, $text],
            'summary' => sprintf('imported %s', $name),
        ];
    }

    /**
     * Where a new import goes when it follows `$import`: the start of the next line. Refused when PHP is closed on the
     * import's line (`use A\\One; ?>`), because that offset is outside the PHP tags and the import would be output text.
     */
    private function lineStartAfterImport(string $content, TextLayout $layout, Stmt\Use_|Stmt\GroupUse $import, string $path): int
    {
        $end = $import->getEndFilePos();
        $at = $layout->nextLineStart($end);
        if (str_contains(substr($content, $end + 1, $at - $end - 1), '?>')) {
            throw new SymbolEditException($path . ' closes PHP on the line of its last import; add the import with replace_text.');
        }

        return $at;
    }

    /**
     * The last statement of the nearest preceding kind (class < function < const) that has imports in the file. A
     * statement mixing kinds in one group belongs to no kind.
     *
     * @param list<Stmt\Use_|Stmt\GroupUse> $imports
     */
    private function lastImportOfEarlierKind(array $imports, int $type): Stmt\Use_|Stmt\GroupUse|null
    {
        $rank = [Stmt\Use_::TYPE_NORMAL => 0, Stmt\Use_::TYPE_FUNCTION => 1, Stmt\Use_::TYPE_CONSTANT => 2];
        $found = null;
        $foundRank = -1;
        foreach ($imports as $use) {
            $types = array_unique(array_column($this->importItems($use), 0));
            $candidate = count($types) === 1 ? ($rank[$types[0]] ?? null) : null;
            if ($candidate !== null && $candidate < $rank[$type] && $candidate >= $foundRank) {
                $found = $use;
                $foundRank = $candidate;
            }
        }

        return $found;
    }

    /**
     * The imports one `use` statement makes: type, fully qualified name and the name it is known by. A grouped statement
     * (`use A\{B, C}`) imports `A\B` and `A\C`; an item without its own type takes the statement's.
     *
     * @return list<array{int, string, string}>
     */
    private function importItems(Stmt\Use_|Stmt\GroupUse $use): array
    {
        $prefix = $use instanceof Stmt\GroupUse ? $use->prefix->toString() . '\\' : '';
        $items = [];
        foreach ($use->uses as $item) {
            $items[] = [
                $item->type !== Stmt\Use_::TYPE_UNKNOWN ? $item->type : $use->type,
                $prefix . $item->name->toString(),
                $item->getAlias()->toString(),
            ];
        }

        return $items;
    }

    /** @param list<Stmt\Use_|Stmt\GroupUse> $uses */
    private function importsSorted(array $uses): bool
    {
        $names = array_map(fn (Stmt\Use_|Stmt\GroupUse $use): string => strtolower($this->importItems($use)[0][1]), $uses);
        $sorted = $names;
        sort($sorted, SORT_STRING);

        return $names === $sorted;
    }

    /** @return array{int, string} insertion offset and text */
    private function firstImport(string $content, SourceSymbols $symbols, string $statement, string $eol, string $path): array
    {
        $layout = new TextLayout($content);
        if ($symbols->namespaces !== []) {
            $namespace = $symbols->namespaces[0];
            $nameEnd = $namespace->name?->getEndFilePos();
            $semicolon = $nameEnd === null ? false : strpos($content, ';', $nameEnd);
            $brace = $nameEnd === null ? false : strpos($content, '{', $nameEnd);
            if ($semicolon === false || ($brace !== false && $brace < $semicolon)) {
                throw new SymbolEditException($path . ' uses a braced namespace; add the import with replace_text.');
            }
            $at = $layout->nextLineStart($semicolon);
        } else {
            $at = 0;
            foreach ($symbols->tokens as $token) {
                if ($token->id === T_OPEN_TAG) {
                    $at = $token->pos + strlen($token->text);
                    continue;
                }
                if ($token->id === T_INLINE_HTML && $at === 0) {
                    continue; // a shebang line or markup before the first `<?php`: the import belongs after the tag
                }
                if ($token->id === T_DECLARE) {
                    $declareEnd = strpos($content, ';', $token->pos);
                    $at = $declareEnd === false ? $at : $layout->nextLineStart($declareEnd);
                    continue;
                }
                if (!in_array($token->id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    break;
                }
            }
            if ($at > 0 && $content[$at - 1] !== "\n") {
                // `<?php class A {}`: the open tag ends mid-line.
                $statement = $eol . $statement;
            }
        }
        $after = substr($content, $at);
        // Exactly one blank line separates the import from the line above and from the code below.
        $leading = $at > 0 && trim($layout->lineAt($at - 1)) !== '' ? $eol : '';
        $trailing = $after === '' || trim(explode("\n", $after)[0]) === '' ? '' : $eol;

        return [$at, $leading . $statement . $eol . $trailing];
    }

    private function assertSingleDeclaration(SourceSymbol $symbol, string $path, string $operation): void
    {
        if ($symbol->declarationCount > 1) {
            throw new SymbolEditException(sprintf(
                '%s is declared together with %d other name(s) in one statement in %s; %s would affect all of them. Use replace_text.',
                $symbol->display(),
                $symbol->declarationCount - 1,
                $path,
                $operation,
            ));
        }
    }

    /** @param array<string, mixed> $edit */
    private function code(string $code, string $indent, string $eol, array $edit, bool $indentFirstLine = false, ?string $unit = null): string
    {
        if (($edit['reindent'] ?? true) === false) {
            return str_replace("\n", $eol, str_replace(["\r\n", "\r"], "\n", $code));
        }

        return TextLayout::reindent($code, $indent, $eol, $indentFirstLine, $unit);
    }
}
