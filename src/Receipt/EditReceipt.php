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
    ) {
    }

    public function succeeded(): bool
    {
        return in_array($this->status, ['prepared', 'runner_succeeded'], true);
    }
}
