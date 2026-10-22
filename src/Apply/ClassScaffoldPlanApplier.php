<?php

declare(strict_types=1);

namespace voku\AgentEdit\Apply;

use Closure;
use voku\AgentEdit\Failure\EditFailure;
use voku\AgentEdit\Failure\FailureCode;
use voku\AgentEdit\Plan\ClassScaffoldPlanDocument;
use voku\AgentMap\Index\AgentMapIndex;

/**
 * Creates the one new file a `class_scaffold_plan` carries, through the shared all-or-nothing `FileTransaction`: the
 * destination must be absent, the content is staged and syntax-checked first, and a failed publication removes the file and
 * exactly the directories it created. Nothing existing is touched.
 */
final readonly class ClassScaffoldPlanApplier implements PlanApplier
{
    private FileTransaction $transaction;

    /**
     * @param (Closure(string, string): bool)|null $renameOperation
     * @param (Closure(string): array{exit_code: int, stdout: string, stderr: string})|null $lintOperation
     */
    public function __construct(?Closure $renameOperation = null, ?Closure $lintOperation = null)
    {
        $this->transaction = new FileTransaction($renameOperation, $lintOperation);
    }

    /** @param array<string, mixed> $plan */
    public function apply(array $plan, AgentMapIndex $map, string $root): EditResult
    {
        $operation = $this->operation($plan, $map, $root);
        $warnings = $this->transaction->run([$operation], $root);

        return new EditResult(
            status: 'runner_succeeded',
            exitCode: 0,
            stdout: ClassScaffoldPlanDocument::PLAN_TYPE . " applied 0 edit(s), 0 move(s), 0 deletion(s) and 1 creation(s) across 1 source file(s).\n",
            stderr: $warnings === [] ? '' : "Class scaffold completed with cleanup warnings:\n- " . implode("\n- ", $warnings),
        );
    }

    /**
     * @param array<string, mixed> $plan
     * @return array{files: array<string, string>, final_paths: array<string, string>, source_hashes: array<string, string>, plan_type: string, edit_count: int, move_count: int, creation_count: int}
     */
    public function preflight(array $plan, AgentMapIndex $map, string $root): array
    {
        $operation = $this->operation($plan, $map, $root);
        $this->transaction->preflight([$operation], $root);

        return [
            'files' => [$operation->path => (string) $operation->content],
            'final_paths' => [$operation->path => $operation->path],
            'source_hashes' => [],
            'plan_type' => ClassScaffoldPlanDocument::PLAN_TYPE,
            'edit_count' => 1,
            'move_count' => 0,
            'creation_count' => 1,
        ];
    }

    /** @param array<string, mixed> $plan */
    private function operation(array $plan, AgentMapIndex $map, string $root): FileOperation
    {
        $document = ClassScaffoldPlanDocument::fromArray($plan);
        $map = $this->withRuntimeRoot($map, $root);
        if ($map->staleEntries() !== []) {
            throw new EditFailure('Current agent-map source evidence is stale; rebuild the map before applying the class scaffold plan.', FailureCode::EvidenceStale);
        }
        $document->provenance->assertMatches($map, false);

        foreach ($map->files as $file) {
            foreach ($file->symbols as $symbol) {
                if (strcasecmp(ltrim($symbol->fqn, '\\'), $document->fqn) === 0) {
                    throw new EditFailure(
                        'Class scaffold target ' . $document->fqn . ' is already declared in ' . $file->path . '; refresh the Map and re-plan.',
                        FailureCode::EvidenceChanged,
                        ['fqn' => $document->fqn, 'path' => $file->path],
                    );
                }
            }
        }

        $realRoot = realpath($root);
        if (!is_string($realRoot)) {
            throw new \RuntimeException('Unable to resolve the map root for the class scaffold.');
        }
        $destination = rtrim(str_replace('\\', '/', $realRoot), '/') . '/' . $document->path;
        if (file_exists($destination) || is_link($destination)) {
            throw new EditFailure('Class scaffold destination already exists: ' . $document->path, FailureCode::TargetExists, ['path' => $document->path]);
        }

        return FileOperation::write($destination, $document->content, null);
    }

    private function withRuntimeRoot(AgentMapIndex $map, string $root): AgentMapIndex
    {
        $root = rtrim(str_replace('\\', '/', $root), '/');
        if (rtrim(str_replace('\\', '/', $map->root), '/') === $root) {
            return $map;
        }

        return new AgentMapIndex($map->schemaVersion, $root, $map->backend, $map->files, $map->relations, $map->diagnostics, $map->fingerprint);
    }
}
