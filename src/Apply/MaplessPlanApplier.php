<?php

declare(strict_types=1);

namespace voku\AgentEdit\Apply;

/** A plan family whose evidence is the current source itself, so it needs no `agent-map` index to apply. */
interface MaplessPlanApplier
{
    /**
     * @param array<string, mixed> $plan
     * @return array{files: array<string, string>, final_paths: array<string, string>, source_hashes: array<string, string>, plan_type: string, edit_count: int, move_count: int, creation_count: int}
     */
    public function preflight(array $plan, string $root): array;

    /** @param array<string, mixed> $plan */
    public function apply(array $plan, string $root): EditResult;
}
