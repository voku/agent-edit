<?php

declare(strict_types=1);

namespace voku\AgentEdit\Plan;

use RuntimeException;

/**
 * Fail-closed wire envelope for the caller-authored `source_edit_plan@1.0`.
 *
 * Unlike every Map plan this one carries no semantic evidence: the *caller* names the exact text it read and the text it
 * wants instead. Everything else (uniqueness of the anchor, file hashes, syntax, API-change detection, transactional
 * publication, receipt, undo) is proven by agent-edit against the current source. Unknown keys are refused so a
 * misspelled field can never be silently ignored.
 */
final readonly class SourceEditPlanDocument
{
    public const TYPE = 'source_edit_plan';
    public const MAX_EDITS = 500;
    public const MAX_CREATES = 100;

    private const PLAN_KEYS = ['type', 'contract_version', 'target_id', 'allow_signature_change', 'edits', 'creates'];
    private const EDIT_KEYS = ['path', 'find', 'replace', 'occurrence', 'all', 'expected_source_sha256'];

    /**
     * Symbol-addressed operations: `op` => keys it requires and keys it may carry besides `path`, `op` and
     * `expected_source_sha256`. Each resolves against the file as read, like every `find` anchor.
     */
    public const SYMBOL_OPERATIONS = [
        'replace_symbol' => [['symbol', 'code'], ['reindent']],
        'replace_body' => [['symbol', 'code'], ['reindent']],
        'insert_member' => [['symbol', 'code'], ['position', 'after', 'before', 'reindent']],
        'remove_symbol' => [['symbol'], []],
        'add_use' => [['name'], ['alias', 'kind']],
    ];
    private const CREATE_KEYS = ['path', 'content'];

    /**
     * @param list<SourceEdit> $edits
     * @param list<SourceCreate> $creates
     */
    public function __construct(
        public string $targetId,
        public bool $allowSignatureChange,
        public array $edits,
        public array $creates,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        if (($data['type'] ?? null) !== self::TYPE) {
            throw new RuntimeException('Unsupported plan type; expected source_edit_plan.');
        }
        if (($data['contract_version'] ?? null) !== '1.0') {
            throw new RuntimeException('Unsupported source_edit_plan contract version; expected "1.0".');
        }
        self::rejectUnknownKeys($data, self::PLAN_KEYS, 'source_edit_plan');

        $targetId = $data['target_id'] ?? null;
        if (!is_string($targetId) || trim($targetId) === '' || strlen($targetId) > 200) {
            throw new RuntimeException('source_edit_plan requires a non-empty target_id (a short label for what this edit is for, max 200 bytes).');
        }
        $allow = $data['allow_signature_change'] ?? false;
        if (!is_bool($allow)) {
            throw new RuntimeException('source_edit_plan allow_signature_change must be a boolean.');
        }

        $rawEdits = $data['edits'] ?? [];
        $rawCreates = $data['creates'] ?? [];
        if (!is_array($rawEdits) || !array_is_list($rawEdits) || !is_array($rawCreates) || !array_is_list($rawCreates)) {
            throw new RuntimeException('source_edit_plan edits and creates must be lists.');
        }
        if ($rawEdits === [] && $rawCreates === []) {
            throw new RuntimeException('source_edit_plan needs at least one edit or one create.');
        }
        if (count($rawEdits) > self::MAX_EDITS || count($rawCreates) > self::MAX_CREATES) {
            throw new RuntimeException(sprintf('source_edit_plan is limited to %d edits and %d creates; split it.', self::MAX_EDITS, self::MAX_CREATES));
        }

        $edits = [];
        $editedPaths = [];
        foreach ($rawEdits as $position => $raw) {
            $edit = self::edit($raw, $position);
            $edits[] = $edit;
            $editedPaths[$edit->path] = true;
        }

        $creates = [];
        $created = [];
        foreach ($rawCreates as $position => $raw) {
            $create = self::create($raw, $position);
            if (isset($created[$create->path])) {
                throw new RuntimeException('source_edit_plan creates ' . $create->path . ' more than once.');
            }
            if (isset($editedPaths[$create->path])) {
                throw new RuntimeException('source_edit_plan both edits and creates ' . $create->path . '; a file cannot be both.');
            }
            $created[$create->path] = true;
            $creates[] = $create;
        }

        return new self($targetId, $allow, $edits, $creates);
    }

    /** @return array<string, mixed> */
    public static function schema(): array
    {
        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'title' => 'agent-edit source_edit_plan@1.0',
            'description' => 'Caller-authored exact edits. Anchor every edit on text you have just read; agent-edit refuses ambiguous, stale or API-changing edits.',
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['type', 'contract_version', 'target_id'],
            'properties' => [
                'type' => ['const' => self::TYPE],
                'contract_version' => ['const' => '1.0'],
                'target_id' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200, 'description' => 'Short label for what the edit is for.'],
                'allow_signature_change' => ['type' => 'boolean', 'default' => false, 'description' => 'Acknowledge that callers of a changed/removed class, function or non-private method were checked by other means. Prefer an agent-map plan.'],
                'edits' => [
                    'type' => 'array',
                    'maxItems' => self::MAX_EDITS,
                    'description' => 'Every edit anchors on the file as you read it (not on the result of earlier edits); edits of one file must not overlap.',
                    'items' => ['oneOf' => [self::replaceSchema(), ...self::symbolSchemas()]],
                ],
                'creates' => [
                    'type' => 'array',
                    'maxItems' => self::MAX_CREATES,
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['path', 'content'],
                        'properties' => [
                            'path' => ['type' => 'string', 'description' => 'Project-relative path that must not exist yet.'],
                            'content' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function replaceSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['path', 'find', 'replace'],
            'properties' => [
                'op' => ['const' => 'replace', 'description' => 'Optional; the default edit kind.'],
                'path' => ['type' => 'string', 'description' => 'Project-relative path of an existing file.'],
                'find' => ['type' => 'string', 'minLength' => 1, 'description' => 'Exact bytes to replace. Must occur exactly once unless occurrence or all is set.'],
                'replace' => ['type' => 'string', 'description' => 'Replacement bytes; empty deletes the match.'],
                'occurrence' => ['type' => 'integer', 'minimum' => 1, 'description' => '1-based match to replace when find occurs more than once.'],
                'all' => ['type' => 'boolean', 'description' => 'Replace every occurrence (at least one must exist).'],
                'expected_source_sha256' => self::shaSchema(),
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function symbolSchemas(): array
    {
        $fields = [
            'symbol' => ['type' => 'string', 'minLength' => 1, 'description' => 'Class, Class::method(), Class::$property, Class::CONSTANT, Enum::CASE or function(); short or fully qualified class name.'],
            'code' => ['type' => 'string', 'description' => 'PHP code; re-indented to its target. In replace_symbol an omitted doc comment or attribute list keeps the existing one.'],
            'reindent' => ['type' => 'boolean', 'default' => true, 'description' => 'false keeps code verbatim (only line endings are adapted).'],
            'position' => ['enum' => ['start', 'end'], 'default' => 'end', 'description' => 'Where insert_member adds the member without after/before.'],
            'after' => ['type' => 'string', 'description' => 'insert_member after this member, for example "bar()", "$baz" or "QUX".'],
            'before' => ['type' => 'string', 'description' => 'insert_member before this member.'],
            'name' => ['type' => 'string', 'minLength' => 1, 'description' => 'Fully qualified name to import; already imported is a no-op.'],
            'alias' => ['type' => 'string', 'description' => 'Import alias.'],
            'kind' => ['enum' => ['class', 'function', 'const'], 'default' => 'class'],
        ];
        $descriptions = [
            'replace_symbol' => 'Replace one whole declaration (method, property, constant, case, class or function).',
            'replace_body' => 'Replace the statements between the braces of a method or function; the signature stays.',
            'insert_member' => 'Add a member to a class, interface, trait or enum (symbol names the class-like).',
            'remove_symbol' => 'Remove one declaration with its doc comment and attributes. References are not checked; non-private API removal needs allow_signature_change.',
            'add_use' => 'Add a namespace-level use import in sorted position.',
        ];
        $schemas = [];
        foreach (self::SYMBOL_OPERATIONS as $operation => [$required, $optional]) {
            $properties = ['op' => ['const' => $operation], 'path' => ['type' => 'string', 'description' => 'Project-relative path of an existing PHP file.']];
            foreach ([...$required, ...$optional] as $key) {
                $properties[$key] = $fields[$key];
            }
            $properties['expected_source_sha256'] = self::shaSchema();
            $schemas[] = [
                'type' => 'object',
                'description' => $descriptions[$operation],
                'additionalProperties' => false,
                'required' => ['op', 'path', ...$required],
                'properties' => $properties,
            ];
        }

        return $schemas;
    }

    /** @return array<string, string> */
    private static function shaSchema(): array
    {
        return ['type' => 'string', 'pattern' => '^sha256:[a-f0-9]{64}$', 'description' => 'Optional: refuse unless the file still has this digest.'];
    }

    private static function edit(mixed $raw, int $position): SourceEdit
    {
        $where = 'source_edit_plan edits[' . $position . ']';
        if (!is_array($raw)) {
            throw new RuntimeException($where . ' must be an object.');
        }
        $operation = $raw['op'] ?? 'replace';
        if ($operation !== 'replace') {
            return self::symbolEdit($raw, $operation, $where);
        }
        unset($raw['op']);
        self::rejectUnknownKeys($raw, self::EDIT_KEYS, $where);

        $find = $raw['find'] ?? null;
        $replace = $raw['replace'] ?? null;
        if (!is_string($find) || $find === '') {
            throw new RuntimeException($where . ' requires a non-empty string find (the exact text to replace).');
        }
        if (!is_string($replace)) {
            throw new RuntimeException($where . ' requires a string replace (use "" to delete the match).');
        }
        if ($find === $replace) {
            throw new RuntimeException($where . ' replaces text with itself; remove the no-op edit.');
        }
        if (str_contains($find, "\0") || str_contains($replace, "\0")) {
            throw new RuntimeException($where . ' contains a NUL byte; binary edits are not supported.');
        }

        $occurrence = $raw['occurrence'] ?? null;
        $all = $raw['all'] ?? false;
        if ($occurrence !== null && (!is_int($occurrence) || $occurrence < 1)) {
            throw new RuntimeException($where . ' occurrence must be an integer >= 1 (1-based).');
        }
        if (!is_bool($all)) {
            throw new RuntimeException($where . ' all must be a boolean.');
        }
        if ($occurrence !== null && $all) {
            throw new RuntimeException($where . ' sets both occurrence and all; choose one.');
        }

        $sha = $raw['expected_source_sha256'] ?? null;
        if ($sha !== null && (!is_string($sha) || preg_match('/\Asha256:[a-f0-9]{64}\z/D', $sha) !== 1)) {
            throw new RuntimeException($where . ' expected_source_sha256 must look like sha256:<64 hex>.');
        }

        return new SourceEdit(self::path($raw['path'] ?? null, $where), $find, $replace, $occurrence, $all, $sha);
    }

    /** @param array<mixed> $raw */
    private static function symbolEdit(array $raw, mixed $operation, string $where): SourceEdit
    {
        if (!is_string($operation) || !isset(self::SYMBOL_OPERATIONS[$operation])) {
            throw new RuntimeException(sprintf(
                '%s op must be "replace" (find/replace) or one of: %s.',
                $where,
                implode(', ', array_keys(self::SYMBOL_OPERATIONS)),
            ));
        }
        [$required, $optional] = self::SYMBOL_OPERATIONS[$operation];
        self::rejectUnknownKeys($raw, ['path', 'op', 'expected_source_sha256', ...$required, ...$optional], $where . ' (' . $operation . ')');
        foreach ($required as $key) {
            if (!is_string($raw[$key] ?? null) || ($key !== 'code' && trim($raw[$key]) === '')) {
                throw new RuntimeException(sprintf('%s (%s) requires a %sstring %s.', $where, $operation, $key === 'code' ? '' : 'non-empty ', $key));
            }
        }
        foreach (['position', 'after', 'before', 'alias', 'kind'] as $key) {
            if (array_key_exists($key, $raw) && !is_string($raw[$key])) {
                throw new RuntimeException(sprintf('%s (%s) %s must be a string.', $where, $operation, $key));
            }
        }
        if (array_key_exists('reindent', $raw) && !is_bool($raw['reindent'])) {
            throw new RuntimeException(sprintf('%s (%s) reindent must be a boolean.', $where, $operation));
        }
        if (isset($raw['after'], $raw['before'])) {
            throw new RuntimeException(sprintf('%s (%s) sets both after and before; choose one.', $where, $operation));
        }
        foreach (['code', 'symbol', 'name'] as $key) {
            if (is_string($raw[$key] ?? null) && str_contains($raw[$key], "\0")) {
                throw new RuntimeException($where . ' contains a NUL byte; binary edits are not supported.');
            }
        }
        $sha = $raw['expected_source_sha256'] ?? null;
        if ($sha !== null && (!is_string($sha) || preg_match('/\Asha256:[a-f0-9]{64}\z/D', $sha) !== 1)) {
            throw new RuntimeException($where . ' expected_source_sha256 must look like sha256:<64 hex>.');
        }
        $path = self::path($raw['path'] ?? null, $where);
        $symbolOperation = array_diff_key($raw, ['expected_source_sha256' => true]);
        $symbolOperation['path'] = $path;

        return new SourceEdit($path, '', '', null, false, $sha, $symbolOperation);
    }

    private static function create(mixed $raw, int $position): SourceCreate
    {
        $where = 'source_edit_plan creates[' . $position . ']';
        if (!is_array($raw)) {
            throw new RuntimeException($where . ' must be an object.');
        }
        self::rejectUnknownKeys($raw, self::CREATE_KEYS, $where);
        $content = $raw['content'] ?? null;
        if (!is_string($content)) {
            throw new RuntimeException($where . ' requires a string content.');
        }
        if (str_contains($content, "\0")) {
            throw new RuntimeException($where . ' contains a NUL byte; binary files are not supported.');
        }

        return new SourceCreate(self::path($raw['path'] ?? null, $where), $content);
    }

    private static function path(mixed $value, string $where): string
    {
        if (!is_string($value) || $value === '' || str_contains($value, "\0")) {
            throw new RuntimeException($where . ' requires a non-empty string path.');
        }
        $path = str_replace('\\', '/', $value);
        if (str_starts_with($path, '/') || preg_match('~^[A-Za-z]:/~', $path) === 1) {
            throw new RuntimeException($where . ' path must be relative to the project root: ' . $value);
        }
        $segments = explode('/', $path);
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new RuntimeException($where . ' path is not a canonical relative path: ' . $value);
            }
        }
        if (in_array($segments[0], ['.git', '.agent-edit'], true)) {
            throw new RuntimeException($where . ' path points into ' . $segments[0] . '/, which direct edits never touch: ' . $value);
        }

        return $path;
    }

    /**
     * @param array<mixed> $data
     * @param list<string> $accepted
     */
    private static function rejectUnknownKeys(array $data, array $accepted, string $where): void
    {
        $unknown = array_values(array_diff(array_map('strval', array_keys($data)), $accepted));
        if ($unknown !== []) {
            throw new RuntimeException(sprintf('%s has unknown key(s) %s; accepted keys: %s.', $where, implode(', ', $unknown), implode(', ', $accepted)));
        }
    }
}
