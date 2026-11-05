<?php

declare(strict_types=1);

namespace voku\AgentEdit\Apply;

use Closure;
use voku\AgentEdit\Plan\MethodCopyPlanDocument;
use voku\AgentMap\Index\AgentMapIndex;

/** Method-copy adapter over the shared exact edit/move host transaction. */
final readonly class MethodCopyPlanApplier implements PlanApplier
{
    private EditMovePlanApplier $applier;

    /**
     * @param (Closure(string, string): bool)|null $renameOperation
     * @param (Closure(string): array{exit_code: int, stdout: string, stderr: string})|null $lintOperation
     */
    public function __construct(?Closure $renameOperation = null, ?Closure $lintOperation = null)
    {
        $this->applier = new EditMovePlanApplier($renameOperation, $lintOperation);
    }

    /** @param array<string, mixed> $plan */
    public function apply(array $plan, AgentMapIndex $map, string $root): EditResult
    {
        return $this->applier->apply(MethodCopyPlanDocument::fromArray($plan), $map, $root);
    }

    /**
     * @param array<string, mixed> $plan
     * @return array{
     *     files: array<string, string>,
     *     final_paths: array<string, string>,
     *     source_hashes: array<string, string>,
     *     plan_type: string,
     *     edit_count: int,
     *     move_count: int
     * }
     */
    public function preflight(array $plan, AgentMapIndex $map, string $root): array
    {
        return $this->applier->preflight(MethodCopyPlanDocument::fromArray($plan), $map, $root);
    }
}
