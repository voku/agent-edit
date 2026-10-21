<?php

declare(strict_types=1);

namespace voku\AgentEdit\Capability;

use voku\AgentEdit\Apply\MaplessPlanApplier;
use voku\AgentEdit\Apply\PlanApplier;
use voku\AgentEdit\Verify\BundleVerifier;
use voku\AgentEdit\Verify\MaplessBundleVerifier;

/** One mutation contract agent-edit can really execute, as opposed to one a planner merely publishes. */
final readonly class PlanCapability
{
    /**
     * @param list<string> $contractVersions
     * @param class-string<PlanApplier|MaplessPlanApplier> $applier
     * @param class-string<BundleVerifier|MaplessBundleVerifier> $verifier
     * @param bool $requiresMap false for caller-authored plans whose only evidence is the current source
     */
    public function __construct(
        public string $planType,
        public array $contractVersions,
        public bool $requiresPhpStan,
        public bool $movesFiles,
        public string $runner,
        public string $applier,
        public string $verifier,
        public bool $requiresMap = true,
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
            'requires_map' => $this->requiresMap,
            'requires_phpstan' => $this->requiresPhpStan,
            'moves_files' => $this->movesFiles,
            'runner' => $this->runner,
        ];
    }
}
