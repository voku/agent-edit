<?php

declare(strict_types=1);

namespace voku\AgentEdit\Verify;

use HelgeSverre\Toon\Toon;
use JsonException;
use RuntimeException;
use voku\AgentEdit\Plan\SourceEditPlanDocument;

/**
 * Verifies an applied `source_edit_plan` bundle against the current source alone: the bound plan is unchanged, every
 * file still has exactly the digest the transaction published, PHP still parses, and the observed changed files are
 * exactly the plan's files. No Map is read.
 */
final readonly class SourceEditVerifier implements MaplessBundleVerifier
{
    public const UNDO_RESULT_FILE = 'undo-result.json';

    /** @return array<string, mixed> */
    public function verify(string $bundle, string $root): array
    {
        $execution = $this->readJson($bundle . '/execution.json');
        if (($execution['status'] ?? null) !== 'runner_succeeded' || ($execution['runner']['dry_run'] ?? null) !== false) {
            throw new RuntimeException('Direct-edit verification requires one successfully applied non-dry execution.');
        }
        if (($execution['runner']['name'] ?? null) !== 'source-edit-plan') {
            throw new RuntimeException('Execution runner identity is not source-edit-plan.');
        }
        if (is_file($bundle . '/' . self::UNDO_RESULT_FILE)) {
            throw new RuntimeException('This edit was undone (' . self::UNDO_RESULT_FILE . ' exists); there is nothing left to verify.');
        }

        $planEvidence = $execution['plan'] ?? null;
        if (!is_array($planEvidence) || !is_string($planEvidence['path'] ?? null) || !is_string($planEvidence['sha256'] ?? null)) {
            throw new RuntimeException('Execution is missing bound plan evidence.');
        }
        $planRaw = @file_get_contents($planEvidence['path']);
        if (!is_string($planRaw) || !hash_equals($planEvidence['sha256'], 'sha256:' . hash('sha256', $planRaw))) {
            throw new RuntimeException('Bound edit plan changed after execution.');
        }
        $decoded = str_ends_with(strtolower($planEvidence['path']), '.toon')
            ? Toon::decode($planRaw)
            : json_decode($planRaw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new RuntimeException('Bound edit plan must decode to an object.');
        }
        $plan = SourceEditPlanDocument::fromArray($decoded);
        if (($planEvidence['type'] ?? null) !== SourceEditPlanDocument::TYPE || ($planEvidence['target_id'] ?? null) !== $plan->targetId) {
            throw new RuntimeException('Execution evidence is not bound to the loaded edit plan identity.');
        }

        $expected = [];
        foreach ($plan->edits as $edit) {
            $expected[$edit->path] = true;
        }
        foreach ($plan->creates as $create) {
            $expected[$create->path] = true;
        }
        $expectedPaths = array_keys($expected);
        sort($expectedPaths, SORT_STRING);

        $recorded = $execution['direct_edit']['files'] ?? null;
        if (!is_array($recorded) || !array_is_list($recorded)) {
            throw new RuntimeException('Execution is missing direct_edit file evidence.');
        }
        $realRoot = realpath($root);
        if (!is_string($realRoot)) {
            throw new RuntimeException('Project root not found: ' . $root);
        }
        $recordedPaths = [];
        $superseded = [];
        foreach ($recorded as $file) {
            if (!is_array($file) || !is_string($file['path'] ?? null) || !is_string($file['after_sha256'] ?? null)) {
                throw new RuntimeException('Execution contains malformed direct_edit file evidence.');
            }
            $recordedPaths[] = $file['path'];
            $absolute = $realRoot . '/' . $file['path'];
            if (is_link($absolute) || !is_file($absolute)) {
                throw new RuntimeException('Edited file is missing after execution: ' . $file['path']);
            }
            $hash = hash_file('sha256', $absolute);
            if (!is_string($hash) || !hash_equals($file['after_sha256'], $hash)) {
                // Another edit touched the file since (a second quick fix in the same file is normal). The exact bytes
                // can no longer prove this edit, so its effect is proven instead; undo stays strictly digest-bound.
                $this->assertEffectPresent($plan, $file, $bundle, $absolute);
                $superseded[] = $file['path'];
            }
            if (str_ends_with(strtolower($file['path']), '.php')) {
                $this->assertParses($absolute, $file['path']);
            }
        }
        sort($superseded, SORT_STRING);
        sort($recordedPaths, SORT_STRING);
        if ($recordedPaths !== $expectedPaths) {
            throw new RuntimeException('Recorded edit evidence does not exactly match the plan scope.');
        }

        $ignored = $execution['direct_edit']['git_ignored'] ?? [];
        if (!is_array($ignored) || !array_is_list($ignored) || array_diff($ignored, $expectedPaths) !== []) {
            throw new RuntimeException('Execution contains invalid git_ignored evidence.');
        }
        $gitExpected = array_values(array_diff($expectedPaths, $ignored));

        $source = $execution['changed_files_source'] ?? null;
        $observed = null;
        if ($source === 'git_status_diff') {
            $changed = $execution['changed_files'] ?? null;
            if (!is_array($changed)) {
                throw new RuntimeException('Execution contains invalid changed_files evidence.');
            }
            $observed = array_values(array_unique(array_map(static fn (mixed $path): string => is_string($path) ? str_replace('\\', '/', $path) : '', $changed)));
            sort($observed, SORT_STRING);
            if ($observed !== $gitExpected) {
                throw new RuntimeException(sprintf(
                    'Observed changed_files do not exactly match plan scope (expected %s; got %s).',
                    implode(', ', $gitExpected),
                    implode(', ', $observed),
                ));
            }
        }

        return [
            'schema_version' => '1.0',
            'kind' => 'source_edit_plan_verification',
            'status' => $observed === null ? 'incomplete' : 'passed',
            'task_id' => (string) ($execution['task_id'] ?? ''),
            'plan' => [
                'type' => SourceEditPlanDocument::TYPE,
                'contract_version' => '1.0',
                'target_id' => $plan->targetId,
                'sha256' => $planEvidence['sha256'],
            ],
            'changed_files' => $observed === null ? $expectedPaths : $this->sortedUnion($observed, $ignored),
            'checks' => [
                'execution_binding' => 'passed',
                'file_digests' => $superseded === [] ? 'passed' : 'superseded_effect_present',
                'php_syntax' => 'passed',
                'changed_files' => $observed === null ? 'unobserved' : 'passed',
            ],
            'files_changed_by_later_edits' => $superseded,
            'git_ignored_files_proven_by_digest_only' => $ignored,
            'acknowledged_api_changes' => $execution['direct_edit']['acknowledged_api_changes'] ?? [],
        ] + ($observed === null ? ['scope' => [
            'status' => 'scope_unproven',
            'changed_files_source' => 'unavailable',
            'proven' => 'plan_file_digests',
            'unproven' => 'files_outside_the_plan',
        ]] : []);
    }

    /**
     * @param list<string> $left
     * @param list<string> $right
     * @return list<string>
     */
    private function sortedUnion(array $left, array $right): array
    {
        $union = array_values(array_unique([...$left, ...$right]));
        sort($union, SORT_STRING);

        return $union;
    }

    /**
     * Proves that one edit's effect is still in a file that has since changed: replaced text is present as often as the
     * pre-image plus the matches this edit rewrote, deleted text is rarer than in the pre-image, and a created file still
     * exists. A later edit that undid the change makes this fail.
     *
     * @param array<string, mixed> $file recorded direct_edit file evidence
     */
    private function assertEffectPresent(SourceEditPlanDocument $plan, array $file, string $bundle, string $absolute): void
    {
        $path = (string) $file['path'];
        if (($file['before_sha256'] ?? null) === null) {
            return; // A created file that still exists: its later content belongs to whoever changed it.
        }
        $preImage = is_string($file['pre_image'] ?? null) ? $bundle . '/' . $file['pre_image'] : '';
        $before = $preImage !== '' && is_file($preImage) && !is_link($preImage) ? file_get_contents($preImage) : false;
        $now = file_get_contents($absolute);
        if (!is_string($before) || !is_string($now) || !hash_equals((string) $file['before_sha256'], hash('sha256', $before))) {
            throw new RuntimeException('Edited file changed after the edit was applied and its pre-image is missing or altered, so the edit cannot be proven: ' . $path);
        }

        foreach ($plan->edits as $edit) {
            if ($edit->path !== $path) {
                continue;
            }
            $matches = substr_count($before, $edit->find);
            $rewritten = $edit->all ? $matches : 1;
            if ($edit->replace === '') {
                $stillThere = substr_count($now, $edit->find) <= $matches - $rewritten;
            } else {
                $stillThere = substr_count($now, $edit->replace) >= substr_count($before, $edit->replace) + $rewritten;
            }
            if (!$stillThere) {
                throw new RuntimeException('Edited file changed after the edit was applied and this edit\'s effect is no longer present: ' . $path);
            }
        }
    }

    private function assertParses(string $absolute, string $display): void
    {
        $pipes = [];
        $process = proc_open([PHP_BINARY, '-n', '-l', $absolute], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start PHP syntax validation.');
        }
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0) {
            throw new RuntimeException('Edited PHP no longer parses: ' . $display);
        }
    }

    /** @return array<string, mixed> */
    private function readJson(string $path): array
    {
        $raw = @file_get_contents($path);
        if (!is_string($raw)) {
            throw new RuntimeException('Unable to read verification input: ' . $path);
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Malformed verification input ' . $path . ': ' . $exception->getMessage(), 0, $exception);
        }
        if (!is_array($decoded)) {
            throw new RuntimeException('Verification input must decode to an object: ' . $path);
        }

        return $decoded;
    }
}
