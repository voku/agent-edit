<?php

declare(strict_types=1);

namespace voku\AgentEdit\Verify;

use voku\AgentEdit\Plan\RenamePlanEditEvidence;
use voku\AgentEdit\Plan\RenamePlanDocument;
use voku\AgentEdit\Plan\EditMovePlanEvidence;
use voku\AgentEdit\Plan\ClassMovePlanDocument;
use voku\AgentEdit\Plan\MethodMovePlanDocument;
use HelgeSverre\Toon\Toon;
use JsonException;
use RuntimeException;
use Throwable;
use voku\AgentMap\MapArtifactPaths;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\IndexReader;

/** Verifies one applied edit/move refactor bundle against current source and Map evidence. */
final readonly class EditMovePlanVerifier implements BundleVerifier
{
    /** Wires verification to the project root and Map reader. */
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
     *   checks: array{execution_binding: string, current_map: string, changed_files: string, replacements: string, moves: string}
     * }
     */
    public function verify(string $bundle, string $mapIndex, string $mapRoot): array
    {
        $execution = $this->readJson($bundle . '/execution.json');
        if (($execution['status'] ?? null) !== 'runner_succeeded'
            || ($execution['runner']['dry_run'] ?? null) !== false
        ) {
            throw new RuntimeException('Refactor verification requires one successfully applied non-dry execution.');
        }
        $taskId = $this->requiredString($execution, 'task_id', 'execution.json');
        $planEvidence = $execution['plan'] ?? null;
        if (!is_array($planEvidence)) {
            throw new RuntimeException('Refactor execution is missing bound plan evidence.');
        }
        $planPath = $this->requiredString($planEvidence, 'path', 'execution plan evidence');
        $planSha256 = $this->requiredString($planEvidence, 'sha256', 'execution plan evidence');
        $planRaw = file_get_contents($planPath);
        if (!is_string($planRaw) || !hash_equals($planSha256, 'sha256:' . hash('sha256', $planRaw))) {
            throw new RuntimeException('Bound refactor plan changed after execution.');
        }
        $plan = $this->decodePlan($planPath, $planRaw);
        $document = $this->decodeDocument($plan);
        $expectedRunner = match ($document->planType()) {
            'class_move_plan' => 'class-move-plan',
            'method_move_plan' => 'method-move-plan',
            default => 'rename-plan',
        };
        if (($execution['runner']['name'] ?? null) !== $expectedRunner) {
            throw new RuntimeException('Refactor execution runner identity does not match the loaded plan family.');
        }
        if (($planEvidence['type'] ?? null) !== $document->planType()
            || ($planEvidence['contract_version'] ?? null) !== '1.0'
            || ($planEvidence['target_id'] ?? null) !== $document->targetId()
        ) {
            throw new RuntimeException('Execution evidence is not bound to the loaded refactor plan identity.');
        }

        $map = $this->withRuntimeRoot($this->reader->read($mapIndex), $mapRoot);
        if ($map->staleEntries() !== []) {
            throw new RuntimeException('Current agent-map evidence is stale after refactor execution.');
        }
        if ($document->requiresPhpStan() && !str_ends_with($map->backend, '+phpstan')) {
            throw new RuntimeException('Current refactor verification requires a PHPStan-backed map for this plan contract.');
        }

        $changedFiles = $execution['changed_files'] ?? null;
        if (!is_array($changedFiles) || ($execution['changed_files_source'] ?? null) !== 'git_status_diff') {
            throw new RuntimeException('Refactor verification requires independently observed changed_files evidence.');
        }
        $actualChanged = [];
        foreach ($changedFiles as $path) {
            if (!is_string($path) || $path === '') {
                throw new RuntimeException('Refactor execution contains invalid changed_files evidence.');
            }
            $actualChanged[] = $this->relativePath($path);
        }
        $actualChanged = array_values(array_unique($actualChanged));
        sort($actualChanged, SORT_STRING);

        $moveTargets = [];
        $expectedChanged = [];
        foreach ($document->moves() as $move) {
            $from = $this->relativePath($move->fromPath);
            $to = $this->relativePath($move->toPath);
            $moveTargets[$from] = $to;
            $expectedChanged[$from] = true;
            $expectedChanged[$to] = true;
            if (file_exists($mapRoot . '/' . $from) || is_link($mapRoot . '/' . $from)) {
                throw new RuntimeException('Moved source still exists after verified refactor: ' . $from);
            }
            if (!is_file($mapRoot . '/' . $to)) {
                throw new RuntimeException('Refactor move destination is missing after execution: ' . $to);
            }
        }

        /** @var array<string, list<RenamePlanEditEvidence>> $editsByFinalPath */
        $editsByFinalPath = [];
        foreach ($document->edits() as $edit) {
            $sourcePath = $this->relativePath($edit->path);
            $finalPath = $moveTargets[$sourcePath] ?? $sourcePath;
            $expectedChanged[$sourcePath] = true;
            if ($sourcePath !== $finalPath) {
                $expectedChanged[$finalPath] = true;
            }
            $editsByFinalPath[$finalPath][] = $edit;
        }

        $expected = array_keys($expectedChanged);
        sort($expected, SORT_STRING);
        if ($actualChanged !== $expected) {
            throw new RuntimeException(sprintf(
                'Observed refactor changed_files do not exactly match plan scope (expected %s; got %s).',
                implode(', ', $expected),
                implode(', ', $actualChanged),
            ));
        }

        foreach ($editsByFinalPath as $finalPath => $edits) {
            $content = file_get_contents($mapRoot . '/' . $finalPath);
            if (!is_string($content)) {
                throw new RuntimeException('Unable to read rewritten source during verification: ' . $finalPath);
            }
            usort($edits, static fn (RenamePlanEditEvidence $left, RenamePlanEditEvidence $right): int => $left->startFilePos <=> $right->startFilePos);
            // Plan offsets are pre-apply positions, and the delta below only accounts for this
            // plan's own replacements. Any other edit to the file -- a docblock removed above the
            // token, a second plan applied first -- shifts every later position without touching
            // the tokens this plan owns. Anchoring on the exact byte would then report a correct
            // rename as a failed one. The replacement is searched for from the expected position
            // instead, and the rewritten source must still contain exactly as many occurrences as
            // the plan rewrote, so a token that genuinely vanished is still caught.
            $offsetDelta = 0;
            $searchCursor = 0;
            $expectedOccurrences = [];
            foreach ($edits as $edit) {
                $removedLength = $edit->endFilePos - $edit->startFilePos + 1;
                if ($edit->replacement === '') {
                    // A same-file move re-inserts the removed text in the file; only occurrences the plan itself
                    // does not put back count as "still present".
                    $reinserted = 0;
                    foreach ($edits as $other) {
                        if ($other->replacement !== '') {
                            $reinserted += substr_count($other->replacement, $edit->expected);
                        }
                    }
                    if (substr_count($content, $edit->expected) > $reinserted) {
                        throw new RuntimeException(sprintf(
                            'Removed refactor source is still present in %s.',
                            $finalPath,
                        ));
                    }
                    $offsetDelta -= $removedLength;
                    continue;
                }

                $expectedOccurrences[$edit->replacement] = ($expectedOccurrences[$edit->replacement] ?? 0) + 1;
                $finalStart = max($searchCursor, $edit->startFilePos + $offsetDelta);
                $found = strpos($content, $edit->replacement, $searchCursor);
                if ($found === false) {
                    throw new RuntimeException(sprintf(
                        'Rewritten token is missing from refactor evidence in %s (expected %s near byte %d).',
                        $finalPath,
                        $edit->replacement,
                        $finalStart,
                    ));
                }
                $searchCursor = $found + strlen($edit->replacement);
                $offsetDelta += strlen($edit->replacement) - $removedLength;
            }
            foreach ($expectedOccurrences as $replacement => $count) {
                if (substr_count($content, (string) $replacement) < $count) {
                    throw new RuntimeException(sprintf(
                        'Rewritten source contains fewer %s occurrences than the refactor plan applied in %s.',
                        (string) $replacement,
                        $finalPath,
                    ));
                }
            }
        }

        foreach (array_keys($editsByFinalPath + array_fill_keys(array_values($moveTargets), [])) as $finalPath) {
            $file = $map->file($finalPath);
            if ($file === null) {
                throw new RuntimeException('Current Map does not contain rewritten refactor source: ' . $finalPath);
            }
            $hash = hash_file('sha256', $mapRoot . '/' . $finalPath);
            if (!is_string($hash) || !hash_equals($file->sha256, 'sha256:' . $hash)) {
                throw new RuntimeException('Current Map hash does not match rewritten refactor source: ' . $finalPath);
            }
        }

        return [
            'schema_version' => '1.0',
            'kind' => match ($document->planType()) {
                'class_move_plan' => 'class_move_plan_verification',
                'method_move_plan' => 'method_move_plan_verification',
                default => 'rename_plan_verification',
            },
            'status' => 'passed',
            'task_id' => $taskId,
            'plan' => [
                'type' => $document->planType(),
                'contract_version' => '1.0',
                'target_id' => $document->targetId(),
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
                'changed_files' => 'passed',
                'replacements' => 'passed',
                'moves' => 'passed',
            ],
        ];
    }

    /** @param array<string, mixed> $plan */
    private function decodeDocument(array $plan): EditMovePlanEvidence
    {
        return match ($plan['type'] ?? null) {
            'class_move_plan' => ClassMovePlanDocument::fromArray($plan),
            'method_move_plan' => MethodMovePlanDocument::fromArray($plan),
            default => RenamePlanDocument::fromArray($plan),
        };
    }

    /** @return array<string, mixed> */
    private function decodePlan(string $path, string $raw): array
    {
        $decoded = str_ends_with(strtolower($path), '.toon')
            ? Toon::decode($raw)
            : json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new RuntimeException('Refactor plan document must decode to an object: ' . $path);
        }

        return $decoded;
    }

    /** Rebinds Map evidence to the runtime source root without changing its semantic contents. */
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
            throw new RuntimeException('Unable to read refactor verification input: ' . $path);
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Malformed refactor verification input ' . $path . ': ' . $exception->getMessage(), 0, $exception);
        }
        if (!is_array($decoded)) {
            throw new RuntimeException('Refactor verification input must decode to an object: ' . $path);
        }

        return $decoded;
    }

    /** Normalizes and validates one project-relative changed-file path. */
    private function relativePath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        if ($path === '' || str_starts_with($path, '/') || preg_match('~^[A-Za-z]:/~', $path) === 1) {
            throw new RuntimeException('Refactor verification requires project-relative source paths.');
        }
        $segments = explode('/', $path);
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new RuntimeException('Refactor verification source path is not normalized: ' . $path);
            }
        }

        return implode('/', $segments);
    }

}
