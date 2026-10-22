<?php

declare(strict_types=1);

namespace voku\AgentEdit\Plan;

use RuntimeException;
use voku\AgentEdit\Failure\EditFailure;
use voku\AgentEdit\Failure\FailureCode;
use voku\AgentMap\Index\AgentMapIndex;

/** Fail-closed decoder for Map's safe method-copy and method-scaffold insertion contracts. */
final readonly class MethodInsertionPlanDocument implements EditMovePlanEvidence
{
    /**
     * @param list<RenamePlanEditEvidence> $edits
     */
    private function __construct(
        private string $type,
        private string $targetId,
        private RenamePlanProvenanceEvidence $provenance,
        private array $edits,
        private string $destinationMethodId,
        private RenamePlanEditEvidence $insertion,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $type = $data['type'] ?? null;
        if (!is_string($type) || !in_array($type, ['method_copy_plan', 'method_scaffold_plan'], true)
            || ($data['contract_version'] ?? null) !== '1.0') {
            throw new RuntimeException('Unsupported agent-map method insertion plan contract.');
        }
        self::safeEvidence($data, $type);

        $targetId = self::string($data, 'target_id', $type);
        if (!str_starts_with($targetId, 'method:')) {
            throw new RuntimeException(ucfirst(str_replace('_', ' ', $type)) . ' target identity must use the method: prefix.');
        }
        $provenance = $data['provenance'] ?? null;
        if (!is_array($provenance)) {
            throw new RuntimeException(ucfirst(str_replace('_', ' ', $type)) . ' requires typed provenance evidence.');
        }
        $rawEdits = $data['edits'] ?? null;
        if (!is_array($rawEdits) || !array_is_list($rawEdits) || $rawEdits === []) {
            throw new RuntimeException('Safe ' . $type . ' requires exact insertion edits.');
        }
        if (isset($data['moves']) && (!is_array($data['moves']) || $data['moves'] !== [])) {
            throw new RuntimeException($type . '@1.0 may not publish file moves.');
        }

        $expectedMethodId = $type === 'method_copy_plan'
            ? self::copyMethodId($data, $targetId)
            : self::scaffoldMethodId($data, $targetId);
        $dependencyResolution = $type === 'method_copy_plan' ? 'copy_dependency' : 'scaffold_dependency';
        $declarationResolution = $type === 'method_copy_plan' ? 'copy_declaration' : 'scaffold_declaration';

        $edits = [];
        $insertion = null;
        $path = null;
        foreach ($rawEdits as $rawEdit) {
            if (!is_array($rawEdit)) {
                throw new RuntimeException($type . ' contains an invalid edit.');
            }
            // A copy needs the `use` imports its body names; the planner publishes each as a zero-length insertion.
            $edit = RenamePlanEditEvidence::fromArray($rawEdit, false, true);
            $path ??= $edit->path;
            if ($edit->path !== $path) {
                throw new RuntimeException($type . ' edits must all target the destination class file.');
            }
            if ($edit->role === 'method_declaration_insertion') {
                if ($insertion !== null) {
                    throw new RuntimeException('Safe ' . $type . ' requires exactly one method declaration insertion.');
                }
                if ($edit->symbolId !== $expectedMethodId || $edit->resolution !== $declarationResolution || $edit->replacement === '') {
                    throw new RuntimeException($type . ' declaration insertion is not bound to its exact destination method.');
                }
                if ($edit->expected !== '}' || $edit->startFilePos !== $edit->endFilePos || $edit->lineStart !== $edit->lineEnd || !str_ends_with($edit->replacement, '}')) {
                    throw new RuntimeException($type . ' declaration insertion must replace exactly the destination class closing brace and keep it.');
                }
                $name = substr($expectedMethodId, (int) strrpos($expectedMethodId, '::') + 2);
                if (self::declaredMethods($edit->replacement) !== [strtolower($name)]) {
                    throw new RuntimeException($type . ' declaration insertion must declare exactly the named method and nothing else.');
                }
                $insertion = $edit;
            } elseif ($edit->role === 'use_statement_insertion') {
                if (!str_starts_with($edit->symbolId, 'use:') || $edit->resolution !== $dependencyResolution || !$edit->isInsertion()) {
                    throw new RuntimeException($type . ' dependency insertion has unexpected evidence.');
                }
                // The planner prefixes a blank line when the destination has no import yet; nothing else is allowed.
                if (preg_match('/\A\n?use [A-Za-z_\\\\\x80-\xff][A-Za-z0-9_\\\\\x80-\xff]*;\n\z/D', $edit->replacement) !== 1
                    || $edit->symbolId !== 'use:' . substr(trim($edit->replacement), 4, -1)
                ) {
                    throw new RuntimeException($type . ' use import edit must insert exactly one plain `use` statement.');
                }
            } else {
                throw new RuntimeException('Unsupported ' . $type . ' edit role: ' . $edit->role . '.');
            }
            $edits[] = $edit;
        }
        if ($insertion === null) {
            throw new RuntimeException('Safe ' . $type . ' requires exactly one method declaration insertion.');
        }

        return new self($type, $targetId, RenamePlanProvenanceEvidence::fromArray($provenance), $edits, $expectedMethodId, $insertion);
    }

    public function planType(): string { return $this->type; }
    public function targetId(): string { return $this->targetId; }
    public function requiresPhpStan(): bool { return true; }
    /** @return list<RenamePlanEditEvidence> */
    public function edits(): array { return $this->edits; }
    /** @return list<RenamePlanMoveEvidence> */
    public function moves(): array { return []; }
    /** `Destination\Class::method`, the identity the rebuilt Map must declare after the apply. */
    public function destinationMethodId(): string { return $this->destinationMethodId; }
    /** Project-relative path of the destination class file every edit targets. */
    public function insertionPath(): string { return $this->insertion->path; }

    public function assertMatches(AgentMapIndex $map): void
    {
        $this->provenance->assertMatches($map, true);

        $separator = (int) strrpos($this->destinationMethodId, '::');
        $destination = substr($this->destinationMethodId, 0, $separator);
        $method = substr($this->destinationMethodId, $separator + 2);
        $file = $map->file($this->insertion->path);
        $class = null;
        foreach ($file === null ? [] : $file->symbols as $symbol) {
            if (ltrim($symbol->fqn, '\\') === $destination && $symbol->kind !== 'function') {
                $class = $symbol;
            }
        }
        if ($class === null) {
            throw new EditFailure('Destination class ' . $destination . ' is not declared in ' . $this->insertion->path . ' by the current Map; refresh the Map and re-plan.', FailureCode::EvidenceChanged, ['destination' => $destination, 'path' => $this->insertion->path]);
        }
        if ($class->lineEnd < 1 || $class->lineEnd !== $this->insertion->lineStart) {
            throw new EditFailure('The insertion is not at the closing brace of ' . $destination . ' in the current Map; refresh the Map and re-plan.', FailureCode::EvidenceChanged, ['destination' => $destination]);
        }
        foreach ($class->methods as $existing) {
            if (strcasecmp($existing->name, $method) === 0) {
                throw new EditFailure($destination . ' already declares ' . $existing->name . '(); refusing to add a second declaration.', FailureCode::EvidenceChanged, ['destination' => $destination, 'method' => $existing->name]);
            }
        }
        if ($this->type === 'method_copy_plan') {
            $this->assertNoAliasedImportDependency($map);
        }
    }


    /**
     * Names (lower-cased) of the methods declared at class level by the text `replacement` would add before the closing
     * brace, found with the PHP tokenizer so strings, comments and closures inside bodies are not mistaken for methods.
     *
     * @return list<string>
     */
    private static function declaredMethods(string $replacement): array
    {
        try {
            $tokens = token_get_all("<?php\nclass AgentEditProbe\n{\n" . $replacement . "\n", TOKEN_PARSE);
        } catch (\ParseError $error) {
            throw new RuntimeException('Method insertion text does not parse as class members: ' . $error->getMessage(), 0, $error);
        }
        $names = [];
        $depth = 0;
        $parentheses = 0;
        $count = count($tokens);
        for ($index = 0; $index < $count; ++$index) {
            $token = $tokens[$index];
            if (is_string($token)) {
                $depth += $token === '{' ? 1 : ($token === '}' ? -1 : 0);
                $parentheses += $token === '(' ? 1 : ($token === ')' ? -1 : 0);
                continue;
            }
            if ($token[0] === T_CURLY_OPEN || $token[0] === T_DOLLAR_OPEN_CURLY_BRACES) {
                ++$depth;
                continue;
            }
            if ($depth !== 1 || $parentheses !== 0) {
                continue;
            }
            // Directly in the class body: a property, constant, enum case or trait use is a second member, not the method.
            if (in_array($token[0], [T_VARIABLE, T_CONST, T_CASE, T_USE], true)) {
                throw new RuntimeException('Method insertion text must add a method and no other class member.');
            }
            if ($token[0] !== T_FUNCTION) {
                continue;
            }
            for ($next = $index + 1; $next < $count; ++$next) {
                $candidate = $tokens[$next];
                if (is_array($candidate) && in_array($candidate[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                if ($candidate === '&') {
                    continue;
                }
                if (is_array($candidate) && $candidate[0] === T_STRING) {
                    $names[] = strtolower($candidate[1]);
                }
                break;
            }
        }

        return $names;
    }

    /**
     * agent-map copies a declaration verbatim but imports the aliased class under its real name, so a body that uses the
     * alias would name a class the destination never imported: valid syntax, fatal at runtime. Until the planner carries the
     * alias, a copy that depends on one is refused rather than published.
     */
    private function assertNoAliasedImportDependency(AgentMapIndex $map): void
    {
        $sourceClass = substr($this->targetId, strlen('method:'), (int) strrpos($this->targetId, '::') - strlen('method:'));
        $sourcePath = null;
        foreach ($map->files as $file) {
            foreach ($file->symbols as $symbol) {
                if (ltrim($symbol->fqn, '\\') === $sourceClass && $symbol->kind !== 'function') {
                    $sourcePath = $file->path;
                }
            }
        }
        $source = $sourcePath === null ? false : @file_get_contents(rtrim($map->root, '/') . '/' . $sourcePath);
        if (!is_string($source)) {
            throw new EditFailure('The source of the copied method could not be read from the current Map; refresh the Map and re-plan.', FailureCode::EvidenceChanged, ['source' => $sourceClass]);
        }

        $aliases = self::aliasedImports($source);
        if ($aliases === []) {
            return;
        }
        $used = self::leadingNames($this->insertion->replacement);
        foreach ($aliases as $alias => $import) {
            if (isset($used[strtolower($alias)])) {
                throw new EditFailure(
                    'Method copy plan depends on the aliased import `' . $import . ' as ' . $alias . '`, which agent-map does not carry into the destination; the copy would name an undefined class. No source was changed.',
                    FailureCode::PlanRefused,
                    ['plan_status' => 'unsupported_alias', 'alias' => $alias, 'import' => $import],
                );
            }
        }
    }

    /** @return array<string, string> alias => imported name, for file-level `use X as Y;` class imports */
    private static function aliasedImports(string $source): array
    {
        $tokens = token_get_all($source);
        $aliases = [];
        $depth = 0;
        $count = count($tokens);
        for ($index = 0; $index < $count; ++$index) {
            $token = $tokens[$index];
            if (is_string($token)) {
                $depth += $token === '{' ? 1 : ($token === '}' ? -1 : 0);
                continue;
            }
            if ($token[0] === T_CURLY_OPEN || $token[0] === T_DOLLAR_OPEN_CURLY_BRACES) {
                ++$depth;
                continue;
            }
            if ($token[0] !== T_USE || $depth !== 0) {
                continue;
            }
            $name = '';
            $alias = null;
            for ($next = $index + 1; $next < $count && $tokens[$next] !== ';'; ++$next) {
                $part = $tokens[$next];
                if (!is_array($part)) {
                    continue;
                }
                if ($part[0] === T_AS) {
                    $alias = '';
                } elseif ($part[0] === T_STRING && $alias !== null) {
                    $alias = $part[1];
                } elseif (in_array($part[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_STRING], true) && $alias === null) {
                    $name = $part[1];
                }
            }
            if ($alias !== null && $alias !== '' && $name !== '') {
                $aliases[$alias] = $name;
            }
        }

        return $aliases;
    }

    /** @return array<string, true> lower-cased first segment of every name the declaration text uses */
    private static function leadingNames(string $declaration): array
    {
        $names = [];
        foreach (token_get_all("<?php\n" . $declaration) as $token) {
            if (!is_array($token) || !in_array($token[0], [T_STRING, T_NAME_QUALIFIED], true)) {
                continue;
            }
            $names[strtolower(explode('\\', $token[1])[0])] = true;
        }

        return $names;
    }


    /** @param array<string, mixed> $data */
    private static function copyMethodId(array $data, string $targetId): string
    {
        $sourceId = self::string($data, 'source_id', 'method copy plan');
        if ($sourceId !== $targetId) {
            throw new RuntimeException('Method copy source identity must exactly match its target identity.');
        }
        $destination = self::string($data, 'destination_fqn', 'method copy plan');
        $name = self::string($data, 'new_method_name', 'method copy plan');
        if ($destination !== ltrim($destination, '\\') || str_contains($destination, '::') || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
            throw new RuntimeException('Method copy destination identity is malformed.');
        }
        $dependencies = $data['owner_dependencies'] ?? null;
        if (!is_array($dependencies)) {
            throw new RuntimeException('Safe method copy plan requires empty owner_dependencies evidence.');
        }
        if ($dependencies !== []) {
            throw PlanRefusal::because('Safe method copy plan requires empty owner_dependencies evidence.', ['status' => 'review_required', 'blockers' => $dependencies]);
        }

        return $destination . '::' . $name;
    }

    /** @param array<string, mixed> $data */
    private static function scaffoldMethodId(array $data, string $targetId): string
    {
        $class = self::string($data, 'target_class', 'method scaffold plan');
        $name = self::string($data, 'method_name', 'method scaffold plan');
        if ($class !== ltrim($class, '\\') || str_contains($class, '::') || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)
            || $targetId !== 'method:' . $class . '::' . $name) {
            throw new RuntimeException('Method scaffold target identity is malformed.');
        }

        return $class . '::' . $name;
    }

    /** @param array<string, mixed> $data */
    private static function safeEvidence(array $data, string $type): void
    {
        if (($data['status'] ?? null) === 'review_required') {
            throw PlanRefusal::because(ucfirst(str_replace('_', ' ', $type)) . ' requires explicit review; no source was changed.', $data);
        }
        if (($data['status'] ?? null) !== 'safe') {
            throw PlanRefusal::because(ucfirst(str_replace('_', ' ', $type)) . ' is not safe; no source was changed.', $data);
        }
        foreach (['blind_spots', 'stale_evidence', 'blockers'] as $field) {
            $value = $data[$field] ?? null;
            if (!is_array($value) || $value !== []) {
                throw new RuntimeException('Safe ' . $type . ' requires empty ' . $field . ' evidence.');
            }
        }
        $notObservable = $data['not_observable'] ?? null;
        if (!is_array($notObservable) || !array_is_list($notObservable)) {
            throw new RuntimeException($type . ' requires not_observable list evidence.');
        }
        foreach ($notObservable as $item) {
            if (!is_string($item) || trim($item) === '') {
                throw new RuntimeException($type . ' contains invalid not_observable evidence.');
            }
        }
    }

    /** @param array<string, mixed> $data */
    private static function string(array $data, string $key, string $plan): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new RuntimeException(ucfirst($plan) . ' requires non-empty string ' . $key . '.');
        }

        return $value;
    }
}
