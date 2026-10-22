<?php

declare(strict_types=1);

namespace voku\AgentEdit\Plan;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\ParserFactory;
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
            $statements = (new ParserFactory())->createForHostVersion()->parse($content) ?? [];
        } catch (Error $error) {
            throw new RuntimeException('Class scaffold content does not parse: ' . $error->getMessage(), 0, $error);
        }

        $namespace = '';
        $declared = [];
        $pending = $statements;
        while ($pending !== []) {
            $statement = array_shift($pending);
            if ($statement instanceof Stmt\Namespace_) {
                $namespace = $statement->name?->toString() ?? '';
                array_unshift($pending, ...$statement->stmts);
                continue;
            }
            $declared[] = match (true) {
                // A `declare` that governs a statement or block would run that code: only `declare(...);` is a directive.
                $statement instanceof Stmt\Declare_ && $statement->stmts === null,
                $statement instanceof Stmt\Use_,
                $statement instanceof Stmt\GroupUse,
                $statement instanceof Stmt\Nop => null,
                $statement instanceof Stmt\ClassLike && !$statement instanceof Stmt\Class_ || $statement instanceof Stmt\Class_ && $statement->name !== null => $statement->name?->toString(),
                default => throw new RuntimeException('Class scaffold content may only declare one type; found top-level code: ' . self::excerpt($content, $statement) . '.'),
            };
        }
        $declared = array_values(array_filter($declared, static fn (?string $name): bool => $name !== null));

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

    private static function excerpt(string $content, Node $statement): string
    {
        $start = $statement->getStartFilePos();
        $text = $start >= 0 ? substr($content, $start, max(0, $statement->getEndFilePos() - $start + 1)) : '';
        $line = trim(strtok($text, "\n") ?: '');

        return $line === '' ? $statement->getType() : $line;
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
