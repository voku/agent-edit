<?php

declare(strict_types=1);

namespace voku\AgentEdit\Cli;

use Closure;
use HelgeSverre\Toon\Toon;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use voku\AgentEdit\Capability\CapabilityRegistry;
use voku\AgentEdit\Plan\SourceEditPlanDocument;
use voku\AgentEdit\EditEngine;
use voku\AgentEdit\Receipt\ApplyRequest;
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
            echo ($direct ? 'Direct edit bundle: ' : 'Refactor execution bundle prepared: ') . "{$receipt->bundleDirectory}\n";
            echo '- plan: ' . ($receipt->planType !== '' ? $receipt->planType : 'unknown') . "\n";
            echo '- target: ' . ($receipt->targetId !== '' ? $receipt->targetId : 'unknown') . "\n";
            echo "- status: {$receipt->status}\n";
            echo "- execution: {$receipt->receiptPath}\n";
            if ($direct) {
                $this->printDirectEditNextSteps($receipt->receiptPath, $receipt->bundleDirectory, $receipt->status);
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
            fwrite(STDERR, '[ERROR] ' . $exception->getMessage() . "\n");

            return 1;
        }
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
     *     staged: array{plan: string, created: list<string>}|null
     * }
     */
    private function parse(array $tokens): array
    {
        $root = realpath($this->projectRoot);
        if (!is_string($root) || !is_dir($root)) {
            throw new InvalidArgumentException('Project root not found: ' . $this->projectRoot);
        }
        $root = str_replace('\\', '/', $root);

        /** @var array<string, string> $values */
        $values = [];
        $dryRun = false;
        $plan = null;
        for ($index = 0, $count = count($tokens); $index < $count; ++$index) {
            $token = $tokens[$index];
            if ($token === '--dry-run') {
                $dryRun = true;
                continue;
            }
            if (!str_starts_with($token, '--')) {
                if ($plan !== null) {
                    throw new InvalidArgumentException('Unexpected argument for `agent-edit apply` (exactly one plan path is accepted): ' . $token);
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
                    throw new InvalidArgumentException('Missing value for option --' . $name);
                }
                ++$index;
            }
            if (!in_array($name, ['task', 'map-index', 'map-root', 'output-dir'], true)) {
                throw new InvalidArgumentException('Unknown option --' . $name . ' for `agent-edit apply` (accepted: --task, --map-index, --map-root, --output-dir, --dry-run).');
            }
            if ($value === '' || isset($values[$name])) {
                throw new InvalidArgumentException('Invalid or duplicate option --' . $name);
            }
            $values[$name] = $value;
        }

        if (!is_string($plan) || trim($plan) === '') {
            throw new InvalidArgumentException('agent-edit apply requires exactly one plan JSON/TOON path (or `-` to read the plan from stdin).');
        }
        $staged = null;
        if ($plan === '-') {
            $planBytes = $this->readStdin !== null ? ($this->readStdin)() : stream_get_contents(STDIN);
            if (!is_string($planBytes) || trim($planBytes) === '') {
                throw new InvalidArgumentException('agent-edit apply - received an empty plan on stdin.');
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
            $planPath = $this->existingFile($root, $plan, 'refactor plan');
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

        $mapRoot = $this->existingDirectory($root, $values['map-root'] ?? $root, 'map root');
        $mapIndex = null;
        if (isset($values['map-index']) || $this->planNeedsMap($planPath)) {
            $mapIndex = $this->existingFile($root, $values['map-index'] ?? MapArtifactPaths::forProject($root)->indexJson(), 'map index');
        }

        return [
            'task_id' => $taskId,
            'plan' => $planPath,
            'map_index' => $mapIndex,
            'map_root' => $mapRoot,
            'output_directory' => $output,
            'dry_run' => $dryRun,
            'staged' => $staged,
        ];
    }

    /** Tells the author which files the edit touched and exactly how to check or reverse it. */
    private function printDirectEditNextSteps(string $receiptPath, string $bundleDirectory, string $status): void
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
            throw new InvalidArgumentException('agent-edit apply requires a valid --task label.');
        }
    }

    private function assertInsideRoot(string $root, string $output): void
    {
        // `verify` only accepts bundles inside the project root, so a receipt written elsewhere could never be verified.
        if (str_contains('/' . $output . '/', '/../') || ($output !== $root && !str_starts_with($output, rtrim($root, '/') . '/'))) {
            throw new InvalidArgumentException('--output-dir must stay inside the project root: ' . $output);
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
            throw new InvalidArgumentException('Refactor ' . $label . ' not found: ' . $path);
        }

        return str_replace('\\', '/', $real);
    }

    /** Resolves an existing in-scope directory for a required refactor input. */
    private function existingDirectory(string $root, string $path, string $label): string
    {
        $resolved = $this->resolvePath($root, $path);
        $real = realpath($resolved);
        if (!is_string($real) || !is_dir($real)) {
            throw new InvalidArgumentException('Refactor ' . $label . ' not found: ' . $path);
        }

        return str_replace('\\', '/', $real);
    }

    /** Resolves a project-relative or absolute refactor path without requiring existence. */
    private function resolvePath(string $root, string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            throw new InvalidArgumentException('Refactor path must not be empty.');
        }
        $path = str_replace('\\', '/', $path);
        if (str_starts_with($path, '/') || preg_match('~^[A-Za-z]:/~', $path) === 1) {
            return rtrim($path, '/');
        }

        return rtrim($root, '/') . '/' . ltrim($path, '/');
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
  --dry-run            Validate the complete plan and current source without mutation.

Every source hash, inclusive
byte range, expected token and plan provenance is revalidated under the project mutation lock.
All rewritten PHP is staged and syntax-checked before publication; every source is restored on any
publication failure. Preconditioned file moves are published in the same transaction as their edits.

TXT;

        return 0;
    }
}
