<?php

declare(strict_types=1);

namespace voku\AgentEdit\Apply;

use Closure;
use voku\AgentEdit\Plan\MethodInsertionPlanDocument;
use voku\AgentMap\Index\AgentMapIndex;

/** Applies Map-owned safe method-copy and method-scaffold plans through the shared transaction. */
final readonly class MethodInsertionPlanApplier implements PlanApplier
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
        return $this->applier->apply(MethodInsertionPlanDocument::fromArray($plan), $map, $root);
    }

    /** @param array<string, mixed> $plan
     * @return array{files: array<string, string>, final_paths: array<string, string>, source_hashes: array<string, string>, plan_type: string, edit_count: int, move_count: int}
     */
    public function preflight(array $plan, AgentMapIndex $map, string $root): array
    {
        return $this->applier->preflight(MethodInsertionPlanDocument::fromArray($plan), $map, $root);
    }
}
