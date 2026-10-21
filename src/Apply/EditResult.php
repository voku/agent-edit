<?php

declare(strict_types=1);

namespace voku\AgentEdit\Apply;

final readonly class EditResult
{
    public function __construct(
        public string $status,
        public ?int $exitCode = null,
        public string $stdout = '',
        public string $stderr = '',
        /** @var array<string, mixed> applier-specific facts a receipt may bind (never authority) */
        public array $evidence = [],
    ) {
    }

    public function succeeded(): bool
    {
        return in_array($this->status, ['prepared', 'runner_succeeded'], true);
    }
}
