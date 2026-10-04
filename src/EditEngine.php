<?php

declare(strict_types=1);

namespace voku\AgentEdit;

use InvalidArgumentException;
use voku\AgentEdit\Apply\ClassConstantRemovalPlanApplier;
use voku\AgentEdit\Apply\ClassMovePlanApplier;
use voku\AgentEdit\Apply\EditResult;
use voku\AgentEdit\Apply\MethodMovePlanApplier;
use voku\AgentEdit\Apply\MethodRemovalPlanApplier;
use voku\AgentEdit\Apply\MutationLock;
use voku\AgentEdit\Apply\PropertyRemovalPlanApplier;
use voku\AgentEdit\Apply\RenamePlanApplier;
use voku\AgentEdit\Capability\CapabilityRegistry;
use voku\AgentMap\Index\AgentMapIndex;

/**
 * Package API for deterministic, evidence-backed source mutation. The CLI is a wrapper over this class.
 *
 * It owns validation and transactional application of an already-produced agent-map plan. Whether a task is
 * allowed to mutate at all stays with the caller: call this only after your own authorization succeeded.
 */
final readonly class EditEngine
{
    public function __construct(
        private RenamePlanApplier $renameApplier = new RenamePlanApplier(),
        private ClassMovePlanApplier $classMoveApplier = new ClassMovePlanApplier(),
        private MethodMovePlanApplier $methodMoveApplier = new MethodMovePlanApplier(),
        private MethodRemovalPlanApplier $methodRemovalApplier = new MethodRemovalPlanApplier(),
        private PropertyRemovalPlanApplier $propertyRemovalApplier = new PropertyRemovalPlanApplier(),
        private ClassConstantRemovalPlanApplier $classConstantRemovalApplier = new ClassConstantRemovalPlanApplier(),
        private MutationLock $mutationLock = new MutationLock(),
        private CapabilityRegistry $capabilities = new CapabilityRegistry(),
    ) {
    }

    public function capabilities(): CapabilityRegistry
    {
        return $this->capabilities;
    }

    /**
     * Validates the complete plan against current source and Map evidence; nothing is written.
     *
     * @param array<string, mixed> $plan decoded plan document
     * @return array{files: array<string, string>, final_paths: array<string, string>, source_hashes: array<string, string>, plan_type: string, edit_count: int, move_count: int}
     */
    public function preflight(array $plan, AgentMapIndex $map, string $repositoryRoot): array
    {
        return $this->applier($plan)->preflight($plan, $map, $repositoryRoot);
    }

    /**
     * Applies the plan transactionally under the project mutation lock: every source is staged, syntax-checked and
     * published together, and restored on any failure.
     *
     * @param array<string, mixed> $plan decoded plan document
     */
    public function apply(array $plan, AgentMapIndex $map, string $repositoryRoot): EditResult
    {
        $applier = $this->applier($plan);

        return $this->mutationLock->synchronized(
            $repositoryRoot,
            static fn (): EditResult => $applier->apply($plan, $map, $repositoryRoot),
        );
    }

    /** @param array<string, mixed> $plan */
    private function applier(array $plan): RenamePlanApplier|ClassMovePlanApplier|MethodMovePlanApplier|MethodRemovalPlanApplier|PropertyRemovalPlanApplier|ClassConstantRemovalPlanApplier
    {
        $type = $plan['type'] ?? null;
        if (!is_string($type) || $this->capabilities->find($type) === null) {
            throw new InvalidArgumentException('Unsupported plan type; see `agent-edit capabilities`.');
        }

        return match ($type) {
            'class_move_plan' => $this->classMoveApplier,
            'method_move_plan' => $this->methodMoveApplier,
            'method_removal_plan' => $this->methodRemovalApplier,
            'property_removal_plan' => $this->propertyRemovalApplier,
            'class_constant_removal_plan' => $this->classConstantRemovalApplier,
            default => $this->renameApplier,
        };
    }
}
