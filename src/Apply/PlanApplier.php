<?php

declare(strict_types=1);

namespace voku\AgentEdit\Apply;

use voku\AgentMap\Index\AgentMapIndex;

/** One versioned plan family's transactional apply boundary; every adapter delegates to the shared host transaction. */
interface PlanApplier
{
    /**
     * @param array<string, mixed> $plan
     */
    public function apply(array $plan, AgentMapIndex $map, string $root): EditResult;

    /**
     * @param array<string, mixed> $plan
     * @return array{files: array<string, string>, final_paths: array<string, string>, source_hashes: array<string, string>, plan_type: string, edit_count: int, move_count: int, deletion_count?: int}
     */
    public function preflight(array $plan, AgentMapIndex $map, string $root): array;
}
