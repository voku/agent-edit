<?php

declare(strict_types=1);

namespace voku\AgentEdit\Cli;

use Closure;
use HelgeSverre\Toon\Toon;
use RuntimeException;
use Throwable;
use voku\AgentEdit\Capability\CapabilityRegistry;
use voku\AgentEdit\Plan\SourceEditPlanDocument;
use voku\AgentEdit\EditEngine;
use voku\AgentEdit\Failure\FailureCode;
use voku\AgentEdit\Failure\InvalidInputFailure;
use voku\AgentEdit\Receipt\ApplyRequest;
use voku\AgentEdit\Receipt\EditReceipt;
use voku\AgentEdit\Receipt\ReceiptNotPersistedException;
use voku\AgentMap\MapArtifactPaths;

/** CLI boundary for consuming one already-produced, versioned agent-map refactor plan. */
final readonly class ApplyCommand
{
    /**
     * @param (Closure(string): void)|null $authorizeMutation host authorization hook called with the label before any
     *                                                       non-dry-run write; it must throw to refuse the write
     * @param (Closure(): string)|null $readStdin source of the plan bytes for `agent-edit apply -` (default: STDIN)
     */
    public function __construct(
        private string $projectRoot,
        private EditEngine $engine = new EditEngine(),
        private ?Closure $authorizeMutation = null,
        private ?Closure $readStdin = null,
    ) {
    }

    /** @param list<string> $tokens */
    public function run(array $tokens): int
    {
        if ($tokens === [] || in_array($tokens[0], ['help', '--help', '-h'], true)) {
            return $this->help();
        }

        $staged = null;
        $json = $this->wantsJson($tokens);
        try {
            $request = $this->parse($tokens);
            $staged = $request['staged'];
            $receipt = $this->engine->applyWithReceipt(new ApplyRequest(
                repositoryRoot: $this->projectRoot,
                planPath: $request['plan'],
                mapIndexPath: $request['map_index'],
                mapRoot: $request['map_root'],
                outputDirectory: $request['output_directory'],
                label: $request['task_id'],
                dryRun: $request['dry_run'],
                authorizeMutation: $this->authorizeMutation,
            ));

            $direct = $receipt->planType === SourceEditPlanDocument::TYPE;
            if ($json) {
                echo FailureReport::encode($this->successPayload($receipt, $request['dry_run'], $request['show_diff'], $direct));

                return $receipt->succeeded() ? 0 : 1;
            }
            echo ($direct ? 'Direct edit bundle: ' : 'Refactor execution bundle prepared: ') . "{$receipt->bundleDirectory}\n";
            echo '- plan: ' . ($receipt->planType !== '' ? $receipt->planType : 'unknown') . "\n";
            echo '- target: ' . ($receipt->targetId !== '' ? $receipt->targetId : 'unknown') . "\n";
            echo "- status: {$receipt->status}\n";
            echo "- execution: {$receipt->receiptPath}\n";
            if ($direct) {
                $this->printDirectEditNextSteps($receipt->receiptPath, $receipt->bundleDirectory, $receipt->status);
            }
            if ($request['dry_run']) {
                foreach ($receipt->plannedChanges as $change) {
                    echo '- would change: ' . $change['path'] . ' (' . $change['change'] . ', +' . $change['lines_added'] . ' -' . $change['lines_removed'] . ")\n";
                }
                if ($request['show_diff'] && $receipt->diff !== '') {
                    echo "\n" . $receipt->diff;
                }
            }

            return $receipt->succeeded() ? 0 : 1;
        } catch (Throwable $exception) {
            // A plan staged from stdin only exists to be bound by a receipt; without one it is just litter.
            if ($staged !== null
                && !($exception instanceof ReceiptNotPersistedException)
                && !is_file(dirname($staged['plan']) . '/' . EditEngine::RECEIPT_FILE)
            ) {
                @unlink($staged['plan']);
                foreach ($staged['created'] as $directory) {
                    if (!is_dir($directory) || (new \FilesystemIterator($directory))->valid() || !@rmdir($directory)) {
                        break;
                    }
                }
            }
            if ($json) {
                echo FailureReport::encode(['schema_version' => '1.0', 'command' => 'apply', 'ok' => false, 'error' => FailureReport::describe($exception)]);

                return 1;
            }
            fwrite(STDERR, '[ERROR] ' . $exception->getMessage() . "\n");

            return 1;
        }
    }

    /** @return array<string, mixed> */
    private function successPayload(EditReceipt $receipt, bool $dryRun, bool $showDiff, bool $direct): array
    {
        $payload = [
            'schema_version' => '1.0',
            'command' => 'apply',
            'ok' => $receipt->succeeded(),
            'status' => $receipt->status,
            'dry_run' => $dryRun,
            'plan' => ['type' => $receipt->planType, 'target_id' => $receipt->targetId],
            'bundle' => $receipt->bundleDirectory,
            'receipt' => $receipt->receiptPath,
        ];
        if ($dryRun) {
            $payload['planned_changes'] = $receipt->plannedChanges;
            if ($showDiff) {
                $payload['diff'] = $receipt->diff;
            }
        } else {
            $payload['changed_files'] = $receipt->changedFiles;
            if ($direct) {
                // Without Git nothing observes the tree; the receipt's own per-file digests still name what the edit wrote.
                $recorded = $this->directEditPaths($receipt->receiptPath);
                if ($receipt->changedFiles === [] && $recorded !== []) {
                    $payload['changed_files'] = $recorded;
                    $payload['changed_files_source'] = 'receipt_digests';
                }
            }
        }
        // A Map plan is verified against a Map rebuilt after the edit; saying so saves the failed verify an agent would try first.
        $payload['next_action'] = $receipt->succeeded() ? ($dryRun ? 'apply' : ($direct ? 'verify' : 'refresh_map_then_verify')) : 'inspect_working_tree';

        return $payload;
    }

    /** @param list<string> $tokens */
    private function wantsJson(array $tokens): bool
    {
        foreach ($tokens as $index => $token) {
            if ($token === '--format=json' || ($token === '--format' && ($tokens[$index + 1] ?? null) === 'json')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $tokens
     * @return array{
     *     task_id: string,
     *     plan: string,
     *     map_index: string|null,
     *     map_root: string,
     *     output_directory: string,
     *     dry_run: bool,
     *     show_diff: bool,
     *     format: 'text'|'json',
     *     staged: array{plan: string, created: list<string>}|null
     * }
     */
    private function parse(array $tokens): array
    {
        $root = realpath($this->projectRoot);
        if (!is_string($root) || !is_dir($root)) {
            throw new InvalidInputFailure('Project root not found: ' . $this->projectRoot, FailureCode::InputNotFound, ['path' => $this->projectRoot]);
        }
        $root = str_replace('\\', '/', $root);

        /** @var array<string, string> $values */
        $values = [];
        $dryRun = false;
        $showDiff = false;
        $plan = null;
        for ($index = 0, $count = count($tokens); $index < $count; ++$index) {
            $token = $tokens[$index];
            if ($token === '--dry-run') {
                $dryRun = true;
                continue;
            }
            if ($token === '--diff') {
                $showDiff = true;
                continue;
            }
            if (!str_starts_with($token, '--')) {
                if ($plan !== null) {
                    throw new InvalidInputFailure('Unexpected argument for `agent-edit apply` (exactly one plan path is accepted): ' . $token, FailureCode::Usage);
                }
                $plan = $token;
                continue;
            }

            $raw = substr($token, 2);
            if (str_contains($raw, '=')) {
                [$name, $value] = explode('=', $raw, 2);
            } else {
                $name = $raw;
                $value = $tokens[$index + 1] ?? null;
                if (!is_string($value) || str_starts_with($value, '--')) {
                    throw new InvalidInputFailure('Missing value for option --' . $name, FailureCode::Usage);
                }
                ++$index;
            }
            if (!in_array($name, ['task', 'map-index', 'map-root', 'output-dir', 'format'], true)) {
                throw new InvalidInputFailure('Unknown option --' . $name . ' for `agent-edit apply` (accepted: --task, --map-index, --map-root, --output-dir, --dry-run, --diff, --format).', FailureCode::Usage);
            }
            if ($value === '' || isset($values[$name])) {
                throw new InvalidInputFailure('Invalid or duplicate option --' . $name, FailureCode::Usage);
            }
            $values[$name] = $value;
        }

        if ($showDiff && !$dryRun) {
            throw new InvalidInputFailure('--diff prints the preview of a --dry-run; add --dry-run (a real apply reports changed_files, and the receipt records the pre-images).', FailureCode::Usage);
        }

        $format = $values['format'] ?? 'text';
        if (!in_array($format, ['text', 'json'], true)) {
            throw new InvalidInputFailure('--format must be text or json.', FailureCode::Usage);
        }
        if (!is_string($plan) || trim($plan) === '') {
            throw new InvalidInputFailure('agent-edit apply requires exactly one plan JSON/TOON path (or `-` to read the plan from stdin).', FailureCode::Usage);
        }
        $staged = null;
        if ($plan === '-') {
            $planBytes = $this->readStdin !== null ? ($this->readStdin)() : stream_get_contents(STDIN);
            if (!is_string($planBytes) || trim($planBytes) === '') {
                throw new InvalidInputFailure('agent-edit apply - received an empty plan on stdin.', FailureCode::Usage);
            }
            $taskId = trim($values['task'] ?? '');
            $taskId = $taskId !== '' ? $taskId : 'plan-' . substr(hash('sha256', $planBytes), 0, 12);
            $this->assertLabel($taskId);
            $output = $this->resolvePath($root, $values['output-dir'] ?? '.agent-edit/receipts/' . $taskId);
            $this->assertInsideRoot($root, $output);
            $planPath = $output . '/plan.json';
            // Deepest first, so cleanup after a refusal can remove exactly what staging created.
            $created = [];
            for ($directory = $output; !is_dir($directory); $directory = dirname($directory)) {
                $created[] = $directory;
            }
            if ($created !== [] && !@mkdir($output, 0o775, true) && !is_dir($output)) {
                throw new RuntimeException('Unable to create the receipt directory: ' . $output);
            }
            if (file_exists($planPath) || file_exists($output . '/' . EditEngine::RECEIPT_FILE)) {
                throw new RuntimeException('A receipt bundle already exists at ' . $output . '; pass another --task or --output-dir so the earlier evidence is not overwritten.');
            }
            if (@file_put_contents($planPath, $planBytes) === false) {
                throw new RuntimeException('Unable to stage the stdin plan: ' . $planPath);
            }
            $staged = ['plan' => $planPath, 'created' => $created];
        } else {
            $planPath = $this->existingFile($root, $plan, 'Plan file');
            $taskId = trim($values['task'] ?? '');
            if ($taskId === '') {
                $planBytes = file_get_contents($planPath);
                if (!is_string($planBytes)) {
                    throw new RuntimeException('Unable to read refactor plan: ' . $plan);
                }
                $taskId = 'plan-' . substr(hash('sha256', $planBytes), 0, 12);
            }
            $this->assertLabel($taskId);
            $output = $this->resolvePath($root, $values['output-dir'] ?? '.agent-edit/receipts/' . $taskId);
            $this->assertInsideRoot($root, $output);
        }

        $mapRoot = $this->existingDirectory($root, $values['map-root'] ?? $root, 'Map root');
        if ($mapRoot !== $root && !str_starts_with($mapRoot, $root . '/')) {
            // `verify` resolves the map root inside the project, so a bundle applied against another tree is unverifiable.
            throw new InvalidInputFailure('--map-root must stay inside the project root: ' . $mapRoot, FailureCode::Usage);
        }
        $mapIndex = null;
        if (isset($values['map-index']) || $this->planNeedsMap($planPath)) {
            $mapIndex = $this->existingFile($root, $values['map-index'] ?? MapArtifactPaths::forProject($root)->indexJson(), 'Map index');
        }

        return [
            'task_id' => $taskId,
            'plan' => $planPath,
            'map_index' => $mapIndex,
            'map_root' => $mapRoot,
            'output_directory' => $output,
            'dry_run' => $dryRun,
            'show_diff' => $showDiff,
            'format' => $format,
            'staged' => $staged,
        ];
    }

    /** @return list<string> project-relative paths a direct edit recorded in its receipt */
    private function directEditPaths(string $receiptPath): array
    {
        $raw = @file_get_contents($receiptPath);
        $receipt = is_string($raw) ? json_decode($raw, true) : null;
        $files = is_array($receipt) && is_array($receipt['direct_edit']['files'] ?? null) ? $receipt['direct_edit']['files'] : [];
        $paths = [];
        foreach ($files as $file) {
            if (is_array($file) && is_string($file['path'] ?? null)) {
                $paths[] = $file['path'];
            }
        }

        return $paths;
    }

    /** Tells the author which files the edit touched and exactly how to check or reverse it. */
    private function printDirectEditNextSteps(string $receiptPath, string $bundleDirectory, string $status): void
    {
        $paths = $this->directEditPaths($receiptPath);
        $root = realpath($this->projectRoot);
        $root = is_string($root) ? rtrim(str_replace('\\', '/', $root), '/') . '/' : '';
        $bundle = str_replace('\\', '/', $bundleDirectory);
        $bundle = $root !== '' && str_starts_with($bundle, $root) ? substr($bundle, strlen($root)) : $bundle;

        if ($paths !== []) {
            echo '- files: ' . implode(', ', $paths) . "\n";
        }
        if ($status === 'runner_succeeded') {
            echo "- verify: agent-edit verify --bundle={$bundle}\n";
            echo "- undo: agent-edit undo --bundle={$bundle}\n";
        }
    }

    private function assertLabel(string $taskId): void
    {
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]*\z/', $taskId) !== 1 || str_contains($taskId, '..')) {
            throw new InvalidInputFailure('agent-edit apply requires a valid --task label.', FailureCode::Usage);
        }
    }

    private function assertInsideRoot(string $root, string $output): void
    {
        // `verify` only accepts bundles inside the project root, so a receipt written elsewhere could never be verified.
        if (str_contains('/' . $output . '/', '/../') || ($output !== $root && !str_starts_with($output, rtrim($root, '/') . '/'))) {
            throw new InvalidInputFailure('--output-dir must stay inside the project root: ' . $output, FailureCode::Usage);
        }
    }

    /** Plans of a Map-free family are applied without reading an index; anything unreadable defaults to needing one. */
    private function planNeedsMap(string $planPath): bool
    {
        $raw = @file_get_contents($planPath);
        if (!is_string($raw)) {
            return true;
        }
        try {
            $decoded = str_ends_with(strtolower($planPath), '.toon') ? Toon::decode($raw) : json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return true;
        }
        $type = is_array($decoded) && is_string($decoded['type'] ?? null) ? $decoded['type'] : null;
        $capability = $type === null ? null : (new CapabilityRegistry())->find($type);

        return $capability === null || $capability->requiresMap;
    }

    /** Resolves an existing in-scope file path for a required refactor input. */
    private function existingFile(string $root, string $path, string $label): string
    {
        $resolved = $this->resolvePath($root, $path);
        $real = realpath($resolved);
        if (!is_string($real) || !is_file($real)) {
            throw new InvalidInputFailure($label . ' not found: ' . $path, FailureCode::InputNotFound, ['path' => $path]);
        }

        return str_replace('\\', '/', $real);
    }

    /** Resolves an existing in-scope directory for a required refactor input. */
    private function existingDirectory(string $root, string $path, string $label): string
    {
        $resolved = $this->resolvePath($root, $path);
        $real = realpath($resolved);
        if (!is_string($real) || !is_dir($real)) {
            throw new InvalidInputFailure($label . ' not found: ' . $path, FailureCode::InputNotFound, ['path' => $path]);
        }

        return str_replace('\\', '/', $real);
    }

    /** Resolves a project-relative or absolute refactor path without requiring existence. */
    private function resolvePath(string $root, string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            throw new InvalidInputFailure('Refactor path must not be empty.', FailureCode::Usage);
        }
        $path = str_replace('\\', '/', $path);
        if (str_starts_with($path, '/') || preg_match('~^[A-Za-z]:/~', $path) === 1) {
            return $this->canonicalAncestor(rtrim($path, '/'));
        }

        return rtrim($root, '/') . '/' . ltrim($path, '/');
    }

    /**
     * Resolves symlinks in the deepest existing ancestor of an absolute path, so a path named through a symlinked
     * directory (a shell's `$PWD`, macOS `/tmp`) compares equal to the real project root it points into.
     */
    private function canonicalAncestor(string $path): string
    {
        $suffix = '';
        for ($ancestor = $path; !file_exists($ancestor); $ancestor = dirname($ancestor)) {
            if (dirname($ancestor) === $ancestor) {
                return $path;
            }
            $suffix = '/' . basename($ancestor) . $suffix;
        }
        $real = realpath($ancestor);

        return is_string($real) ? rtrim(str_replace('\\', '/', $real), '/') . $suffix : $path;
    }

    /** Prints the supported agent-edit apply CLI contract. */
    private function help(): int
    {
        echo <<<'TXT'
Usage:
  agent-edit apply PLAN|- [options]

Consumes one safe versioned plan through agent-edit's deterministic mutation boundary (`-` reads it from stdin).
The fixed allowlist is `agent-edit capabilities`: the agent-map plan families plus the caller-authored
source_edit_plan@1.0 (see `agent-edit schema`), which needs no Map. Each family keeps its own wire decoder and
semantic invariants; Rector execution and any other plan type remain rejected.

Options:
  --task LABEL         Receipt label. Default: plan-<plan sha256 prefix>.
  --map-index PATH     Current agent-map JSON/TOON. Default: .agent-map/php-symbols.json (not needed for source_edit_plan)
  --map-root PATH      Runtime source root for hash/currentness checks. Default: project root.
  --output-dir PATH    Receipt bundle. Default: .agent-edit/receipts/<label>
  --dry-run            Validate the complete plan and current source without mutation; lists the files it would change.
  --diff               With --dry-run: also print the unified diff of every change it would make.
  --format=text|json   json prints exactly one document on stdout, also for a failure (error.code, error.next_action,
                       error.hint, error.details). Exit codes are the same in both formats.

Every source hash, inclusive
byte range, expected token and plan provenance is revalidated under the project mutation lock.
All rewritten PHP is staged and syntax-checked before publication; every source is restored on any
publication failure. Preconditioned file moves are published in the same transaction as their edits.

TXT;

        return 0;
    }
}
