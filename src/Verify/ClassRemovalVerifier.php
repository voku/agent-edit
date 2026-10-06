<?php

declare(strict_types=1);

namespace voku\AgentEdit\Verify;

use voku\AgentEdit\Plan\ClassRemovalPlanDocument;
use HelgeSverre\Toon\Toon;
use JsonException;
use RuntimeException;
use Throwable;
use voku\AgentMap\MapArtifactPaths;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\IndexReader;

/** Verifies one applied class-removal bundle against current source and Map evidence. */
final readonly class ClassRemovalVerifier implements BundleVerifier
{
    public function __construct(
        private IndexReader $reader = new IndexReader(),
    ) {
    }

    /**
     * @return array{
     *   schema_version: string,
     *   kind: string,
     *   status: string,
     *   task_id: string,
     *   plan: array{type: string, contract_version: string, target_id: string, sha256: string},
     *   map: array{backend: string, digest: string},
     *   changed_files: list<string>,
     *   checks: array{execution_binding: string, current_map: string, changed_files: string, target_absent: string, source_absent: string}
     * }
     */
    public function verify(string $bundle, string $mapIndex, string $mapRoot): array
    {
        $execution = $this->readJson($bundle . '/execution.json');
        if (($execution['status'] ?? null) !== 'runner_succeeded'
            || ($execution['runner']['name'] ?? null) !== 'class-removal-plan'
            || ($execution['runner']['dry_run'] ?? null) !== false
        ) {
            throw new RuntimeException('Class removal verification requires one successfully applied non-dry removal execution.');
        }

        $taskId = $this->requiredString($execution, 'task_id', 'execution.json');
        $planEvidence = $execution['plan'] ?? null;
        if (!is_array($planEvidence)) {
            throw new RuntimeException('Class removal execution is missing bound plan evidence.');
        }
        $planPath = $this->requiredString($planEvidence, 'path', 'execution plan evidence');
        $planSha256 = $this->requiredString($planEvidence, 'sha256', 'execution plan evidence');
        $planRaw = file_get_contents($planPath);
        if (!is_string($planRaw) || !hash_equals($planSha256, 'sha256:' . hash('sha256', $planRaw))) {
            throw new RuntimeException('Bound class removal plan changed after execution.');
        }
        $plan = $this->decodePlan($planPath, $planRaw);
        $document = ClassRemovalPlanDocument::fromArray($plan);
        if (($planEvidence['type'] ?? null) !== 'class_removal_plan'
            || ($planEvidence['contract_version'] ?? null) !== '1.0'
            || ($planEvidence['target_id'] ?? null) !== $document->targetId
        ) {
            throw new RuntimeException('Execution evidence is not bound to the loaded class removal plan identity.');
        }

        $map = $this->withRuntimeRoot($this->reader->read($mapIndex), $mapRoot);
        if ($map->staleEntries() !== []) {
            throw new RuntimeException('Current agent-map evidence is stale after class removal execution.');
        }
        if (!str_ends_with($map->backend, '+phpstan')) {
            throw new RuntimeException('Class removal verification requires a PHPStan-backed current map.');
        }

        $viaManifest = ($execution['changed_files_source'] ?? null) === MapManifestEvidence::SOURCE;
        if ($viaManifest) {
            $actualChanged = (new MapManifestEvidence())->observe($execution, $bundle, $map, $mapRoot);
        } else {
            $changedFiles = $execution['changed_files'] ?? null;
            if (!is_array($changedFiles) || ($execution['changed_files_source'] ?? null) !== 'git_status_diff') {
                throw new RuntimeException('Class removal verification requires independently observed changed_files evidence.');
            }
            $actualChanged = [];
            foreach ($changedFiles as $path) {
                if (!is_string($path) || $path === '') {
                    throw new RuntimeException('Class removal execution contains invalid changed_files evidence.');
                }
                $actualChanged[] = $this->relativePath($path);
            }
            $actualChanged = array_values(array_unique($actualChanged));
            sort($actualChanged, SORT_STRING);
        }

        $deleted = $this->relativePath($document->path);
        $expected = [$deleted];
        if ($actualChanged !== $expected) {
            throw new RuntimeException(sprintf(
                'Observed class-removal changed_files do not exactly match plan scope (expected %s; got %s).',
                implode(', ', $expected),
                implode(', ', $actualChanged),
            ));
        }

        if (file_exists($mapRoot . '/' . $deleted) || is_link($mapRoot . '/' . $deleted) || $map->file($deleted) !== null) {
            throw new RuntimeException('Removed class source is still present: ' . $deleted);
        }
        foreach ($map->files as $file) {
            foreach ($file->symbols as $symbol) {
                if ($symbol->id() === $document->targetId) {
                    throw new RuntimeException('Removed class is still present in the current Map: ' . $document->targetId);
                }
            }
        }
        foreach ($map->relations as $relation) {
            if (in_array($document->targetId, $relation->targetIds, true)) {
                throw new RuntimeException('Removed class still has incoming Map evidence at ' . $relation->file . ':' . $relation->lineStart . '.');
            }
        }

        return [
            'schema_version' => '1.0',
            'kind' => 'class_removal_plan_verification',
            'status' => $viaManifest ? 'incomplete' : 'passed',
            'task_id' => $taskId,
            'plan' => [
                'type' => 'class_removal_plan',
                'contract_version' => '1.0',
                'target_id' => $document->targetId,
                'sha256' => $planSha256,
            ],
            'map' => [
                'backend' => $map->backend,
                'digest' => $map->mapDigest(),
            ],
            'changed_files' => $actualChanged,
            'checks' => [
                'execution_binding' => 'passed',
                'current_map' => 'passed',
                'changed_files' => $viaManifest ? 'map_indexed_files_only' : 'passed',
                'target_absent' => 'passed',
                'source_absent' => 'passed',
            ],
        ] + ($viaManifest ? ['scope' => MapManifestEvidence::scope()] : []);
    }

    /** @return array<string, mixed> */
    private function decodePlan(string $path, string $raw): array
    {
        $decoded = str_ends_with(strtolower($path), '.toon') ? Toon::decode($raw) : json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new RuntimeException('Class removal plan document must decode to an object: ' . $path);
        }

        return $decoded;
    }

    private function withRuntimeRoot(AgentMapIndex $map, string $root): AgentMapIndex
    {
        $runtimeRoot = rtrim(str_replace('\\', '/', $root), '/');
        if (rtrim(str_replace('\\', '/', $map->root), '/') === $runtimeRoot) {
            return $map;
        }

        return new AgentMapIndex(
            $map->schemaVersion,
            $runtimeRoot,
            $map->backend,
            $map->files,
            $map->relations,
            $map->diagnostics,
            $map->fingerprint,
        );
    }

    /** @param array<string, mixed> $data */
    private function requiredString(array $data, string $key, string $source): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new RuntimeException($source . ' is missing non-empty ' . $key . '.');
        }

        return $value;
    }

    /** @return array<string, mixed> */
    private function readJson(string $path): array
    {
        $raw = file_get_contents($path);
        if (!is_string($raw)) {
            throw new RuntimeException('Unable to read class removal verification input: ' . $path);
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Malformed class removal verification input ' . $path . ': ' . $exception->getMessage(), 0, $exception);
        }
        if (!is_array($decoded)) {
            throw new RuntimeException('Class removal verification input must decode to an object: ' . $path);
        }

        return $decoded;
    }

    private function relativePath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        if ($path === '' || str_starts_with($path, '/') || preg_match('~^[A-Za-z]:/~', $path) === 1) {
            throw new RuntimeException('Class removal verification requires project-relative source paths.');
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new RuntimeException('Class removal verification source path is not normalized: ' . $path);
            }
        }

        return $path;
    }

}
