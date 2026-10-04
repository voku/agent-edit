<?php

declare(strict_types=1);

namespace voku\AgentEdit\Capability;

/** One mutation contract agent-edit can really execute, as opposed to one a planner merely publishes. */
final readonly class PlanCapability
{
    /** @param list<string> $contractVersions */
    public function __construct(
        public string $planType,
        public array $contractVersions,
        public bool $requiresPhpStan,
        public bool $movesFiles,
        public string $runner,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'plan_type' => $this->planType,
            'contract_versions' => $this->contractVersions,
            'preflight' => true,
            'apply' => true,
            'verify' => true,
            'dry_run' => true,
            'transactional' => true,
            'requires_phpstan' => $this->requiresPhpStan,
            'moves_files' => $this->movesFiles,
            'runner' => $this->runner,
        ];
    }
}
