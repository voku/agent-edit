<?php

declare(strict_types=1);

namespace voku\AgentEdit\Receipt;

/**
 * Handle to one agent-edit receipt bundle. The persisted `execution.json` is an agent-edit receipt
 * (schema_version 1.0); its name and `task_id`/`runner` fields are kept only for compatibility with hosts
 * that already consume them.
 */
final readonly class EditReceipt
{
    public function __construct(
        public string $status,
        public ?int $exitCode,
        public string $bundleDirectory,
        public string $receiptPath,
        public string $planType,
        public string $targetId,
        /** @var list<array{path: string, change: string, final_path?: string, lines_added: int, lines_removed: int}> what a dry run found it would change; empty for a real apply */
        public array $plannedChanges = [],
        /** unified diff of `plannedChanges`; only a dry run fills it */
        public string $diff = '',
        /** @var list<string> files the apply was observed to change (git or Map-manifest evidence); empty for a dry run */
        public array $changedFiles = [],
    ) {
    }

    public function succeeded(): bool
    {
        return in_array($this->status, ['prepared', 'runner_succeeded'], true);
    }
}
