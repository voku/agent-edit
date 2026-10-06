<?php

declare(strict_types=1);

namespace voku\AgentEdit\Capability;

use InvalidArgumentException;
use voku\AgentEdit\Apply\ClassConstantRemovalPlanApplier;
use voku\AgentEdit\Apply\ClassMovePlanApplier;
use voku\AgentEdit\Apply\ClassRemovalPlanApplier;
use voku\AgentEdit\Apply\MethodMovePlanApplier;
use voku\AgentEdit\Apply\MethodRemovalPlanApplier;
use voku\AgentEdit\Apply\PropertyRemovalPlanApplier;
use voku\AgentEdit\Apply\RenamePlanApplier;
use voku\AgentEdit\Verify\ClassConstantRemovalVerifier;
use voku\AgentEdit\Verify\ClassRemovalVerifier;
use voku\AgentEdit\Verify\EditMovePlanVerifier;
use voku\AgentEdit\Verify\MethodRemovalVerifier;
use voku\AgentEdit\Verify\PropertyRemovalVerifier;

/**
 * Single owner of the executable plan allowlist. Routing, `agent-edit capabilities` and the verify dispatcher
 * all read this table so a published capability can never drift from what apply actually accepts.
 */
final readonly class CapabilityRegistry
{
    public const SCHEMA_VERSION = '1.0';

    /** @return list<PlanCapability> */
    public function all(): array
    {
        $capabilities = [];
        foreach (['method', 'function', 'class', 'property', 'class_constant', 'parameter'] as $kind) {
            $capabilities[] = new PlanCapability(
                $kind . '_rename_plan',
                ['1.0'],
                in_array($kind, ['method', 'function', 'parameter', 'property'], true),
                $kind === 'class',
                'rename-plan',
                RenamePlanApplier::class,
                EditMovePlanVerifier::class,
            );
        }

        // class_move_plan@1.0 requires PHPStan only when its own provenance names a +phpstan backend.
        $capabilities[] = new PlanCapability('class_move_plan', ['1.0'], false, true, 'class-move-plan', ClassMovePlanApplier::class, EditMovePlanVerifier::class);
        $capabilities[] = new PlanCapability('method_move_plan', ['1.0'], true, false, 'method-move-plan', MethodMovePlanApplier::class, EditMovePlanVerifier::class);
        $capabilities[] = new PlanCapability('method_removal_plan', ['1.0'], true, false, 'method-removal-plan', MethodRemovalPlanApplier::class, MethodRemovalVerifier::class);
        $capabilities[] = new PlanCapability('property_removal_plan', ['1.0'], true, false, 'property-removal-plan', PropertyRemovalPlanApplier::class, PropertyRemovalVerifier::class);
        $capabilities[] = new PlanCapability('class_constant_removal_plan', ['1.0'], true, false, 'class-constant-removal-plan', ClassConstantRemovalPlanApplier::class, ClassConstantRemovalVerifier::class);
        $capabilities[] = new PlanCapability('class_removal_plan', ['1.0'], true, false, 'class-removal-plan', ClassRemovalPlanApplier::class, ClassRemovalVerifier::class);

        return $capabilities;
    }

    /** Routes a persisted `execution.json` runner identity back to its capability. */
    public function findByRunner(string $runner): ?PlanCapability
    {
        foreach ($this->all() as $capability) {
            if ($capability->runner === $runner) {
                return $capability;
            }
        }

        return null;
    }

    public function find(string $planType): ?PlanCapability
    {
        foreach ($this->all() as $capability) {
            if ($capability->planType === $planType) {
                return $capability;
            }
        }

        return null;
    }

    /** @return array{schema_version: string, capabilities: list<array<string, mixed>>} */
    public function toArray(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'capabilities' => array_map(static fn (PlanCapability $capability): array => $capability->toArray(), $this->all()),
        ];
    }

    /**
     * Intersects this registry with an `agent-map plan-capabilities --format=json` payload.
     *
     * A plan is executable only when Map can publish that exact type@version and agent-edit can apply it.
     *
     * @param array<mixed> $mapPayload decoded `agent-map plan-capabilities` document
     * @return array{
     *     schema_version: string,
     *     executable: list<array<string, mixed>>,
     *     planned_not_executable: list<array<string, mixed>>,
     *     executable_not_planned: list<string>
     * }
     */
    public function intersect(array $mapPayload): array
    {
        $entries = $mapPayload['capabilities'] ?? null;
        if (($mapPayload['type'] ?? null) !== 'plan_capabilities' || !is_array($entries) || !array_is_list($entries)) {
            throw new InvalidArgumentException('Expected an `agent-map plan-capabilities --format=json` document.');
        }

        $executable = [];
        $notExecutable = [];
        $planned = [];
        foreach ($entries as $entry) {
            if (!is_array($entry) || !is_string($entry['plan_type'] ?? null) || !is_string($entry['contract_version'] ?? null)) {
                throw new InvalidArgumentException('Map plan capability entry is malformed.');
            }
            $own = $this->find($entry['plan_type']);
            if ($own !== null && in_array($entry['contract_version'], $own->contractVersions, true)) {
                $planned[$entry['plan_type']] = true;
                $executable[] = ['map' => $entry, 'edit' => $own->toArray()];
                continue;
            }
            $notExecutable[] = $entry;
        }

        $unplanned = [];
        foreach ($this->all() as $capability) {
            if (!isset($planned[$capability->planType])) {
                $unplanned[] = $capability->planType;
            }
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'executable' => $executable,
            'planned_not_executable' => $notExecutable,
            'executable_not_planned' => $unplanned,
        ];
    }
}
