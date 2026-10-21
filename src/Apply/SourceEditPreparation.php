<?php

declare(strict_types=1);

namespace voku\AgentEdit\Apply;

/** The fully resolved, not yet written outcome of one `source_edit_plan`. */
final readonly class SourceEditPreparation
{
    /**
     * @param list<FileOperation> $operations
     * @param list<array{path: string, before_sha256: string|null, after_sha256: string, before: string|null, after: string, edits: int}> $files
     * @param list<string> $acknowledgedApiChanges API changes the plan explicitly allowed
     */
    public function __construct(
        public array $operations,
        public array $files,
        public int $editCount,
        public int $creationCount,
        public array $acknowledgedApiChanges,
    ) {
    }
}
