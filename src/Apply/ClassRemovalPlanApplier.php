<?php

declare(strict_types=1);

namespace voku\AgentEdit\Apply;

use Closure;
use RuntimeException;
use Throwable;
use voku\AgentEdit\Plan\ClassRemovalPlanDocument;
use voku\AgentMap\Index\AgentMapIndex;

/**
 * Applies one safe class_removal_plan@1.0: deletes the single owned source file after re-proving the plan against
 * current Map and disk evidence. The file is moved aside, hash-checked and only then discarded, and restored on
 * any failure, mirroring the backup/rollback discipline of the shared edit/move transaction.
 */
final readonly class ClassRemovalPlanApplier implements PlanApplier
{
    /** @var (Closure(string, string): bool)|null */
    private ?Closure $renameOperation;

    /** @param (Closure(string, string): bool)|null $renameOperation */
    public function __construct(?Closure $renameOperation = null)
    {
        $this->renameOperation = $renameOperation;
    }

    /** @param array<string, mixed> $plan */
    public function apply(array $plan, AgentMapIndex $map, string $root): EditResult
    {
        $prepared = $this->preflight($plan, $map, $root);
        $source = array_key_first($prepared['source_hashes']);
        if ($source === null) {
            throw new RuntimeException('Class removal preflight produced no deletion; no source was changed.');
        }
        $expected = $prepared['source_hashes'][$source];

        $backup = $source . '.agent-edit-plan-backup-' . bin2hex(random_bytes(8));
        if (file_exists($backup) || is_link($backup)) {
            throw new RuntimeException('Class removal temporary path already exists: ' . $backup);
        }

        try {
            if (!$this->move($source, $backup)) {
                throw new RuntimeException('Unable to back up class source before deletion: ' . $source);
            }
            $hash = hash_file('sha256', $backup);
            if (!is_string($hash) || !hash_equals($expected, 'sha256:' . $hash)) {
                throw new RuntimeException('Class source changed during deletion precondition capture: ' . $source);
            }
        } catch (Throwable $exception) {
            if (is_file($backup) && !file_exists($source) && !$this->move($backup, $source)) {
                throw new RuntimeException('Class removal failed and the original source could not be restored from ' . $backup, 0, $exception);
            }

            throw new RuntimeException('Class removal failed; the source file was restored.', 0, $exception);
        }

        $warning = @unlink($backup) ? '' : "Class removal completed with cleanup warning:\n- unable to remove backup " . $backup;

        return new EditResult(
            status: 'runner_succeeded',
            exitCode: 0,
            stdout: ClassRemovalPlanDocument::PLAN_TYPE . " applied 0 edit(s), 0 move(s) and 1 deletion(s) across 1 source file(s).\n",
            stderr: $warning,
        );
    }

    /**
     * @param array<string, mixed> $plan
     * @return array{files: array<string, string>, final_paths: array<string, string>, source_hashes: array<string, string>, plan_type: string, edit_count: int, move_count: int, deletion_count: int}
     */
    public function preflight(array $plan, AgentMapIndex $map, string $root): array
    {
        $document = ClassRemovalPlanDocument::fromArray($plan);
        $map = $this->withRuntimeRoot($map, $root);
        if ($map->staleEntries() !== []) {
            throw new RuntimeException('Current agent-map source evidence is stale; rebuild the map before applying the class removal plan.');
        }
        $document->provenance->assertMatches($map, true);

        $file = $map->file($document->path);
        if ($file === null) {
            throw new RuntimeException('Class removal source is not indexed by the current Map: ' . $document->path);
        }
        if (count($file->symbols) !== 1 || $file->symbols[0]->id() !== $document->targetId) {
            throw new RuntimeException('Class removal source must declare only the target class in the current Map; rebuild and re-plan.');
        }
        if (!hash_equals($document->sourceSha256, $file->sha256)) {
            throw new RuntimeException('Class removal evidence changed before apply; rebuild and re-plan.');
        }
        foreach ($map->relations as $relation) {
            if (!in_array($document->targetId, $relation->targetIds, true)) {
                continue;
            }
            if ($relation->kind === 'defines' && $relation->sourceId === 'file:' . $document->path) {
                continue;
            }
            throw new RuntimeException(sprintf(
                'Class removal target still has incoming %s evidence at %s:%d; rebuild and re-plan.',
                $relation->kind,
                $relation->file,
                $relation->lineStart,
            ));
        }

        $path = $this->sourcePath($root, $document->path);
        $hash = hash_file('sha256', $path);
        if (!is_string($hash) || !hash_equals($document->sourceSha256, 'sha256:' . $hash)) {
            throw new RuntimeException('Class removal evidence changed before apply; rebuild and re-plan.');
        }

        return [
            'files' => [],
            'final_paths' => [],
            'source_hashes' => [$path => $document->sourceSha256],
            'plan_type' => ClassRemovalPlanDocument::PLAN_TYPE,
            'edit_count' => 0,
            'move_count' => 0,
            'deletion_count' => 1,
        ];
    }

    private function sourcePath(string $root, string $relative): string
    {
        $relative = str_replace('\\', '/', $relative);
        if ($relative === '' || str_contains($relative, "\0") || str_starts_with($relative, '/') || preg_match('~^[A-Za-z]:/~', $relative) === 1) {
            throw new RuntimeException('Class removal path must be relative to the map root: ' . $relative);
        }
        foreach (explode('/', $relative) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new RuntimeException('Class removal plan contains a non-canonical relative path: ' . $relative);
            }
        }
        $realRoot = realpath($root);
        $absolute = $realRoot === false ? $root . '/' . $relative : $realRoot . '/' . $relative;
        if ($realRoot === false || is_link($absolute) || !is_file($absolute)) {
            throw new RuntimeException('Class removal source is not a regular file inside the map root: ' . $relative);
        }
        $path = realpath($absolute);
        if (!is_string($path) || !str_starts_with(str_replace('\\', '/', $path), rtrim(str_replace('\\', '/', $realRoot), '/') . '/')) {
            throw new RuntimeException('Class removal source path escapes the map root: ' . $relative);
        }

        return $path;
    }

    private function move(string $from, string $to): bool
    {
        return $this->renameOperation !== null ? ($this->renameOperation)($from, $to) : rename($from, $to);
    }

    private function withRuntimeRoot(AgentMapIndex $map, string $root): AgentMapIndex
    {
        $root = rtrim(str_replace('\\', '/', $root), '/');
        if (rtrim(str_replace('\\', '/', $map->root), '/') === $root) {
            return $map;
        }

        return new AgentMapIndex(
            $map->schemaVersion,
            $root,
            $map->backend,
            $map->files,
            $map->relations,
            $map->diagnostics,
            $map->fingerprint,
        );
    }
}
