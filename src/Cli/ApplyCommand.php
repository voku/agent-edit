<?php

declare(strict_types=1);

namespace voku\AgentEdit\Cli;

use Closure;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use voku\AgentEdit\EditEngine;
use voku\AgentEdit\Receipt\ApplyRequest;
use voku\AgentMap\MapArtifactPaths;

/** CLI boundary for consuming one already-produced, versioned agent-map refactor plan. */
final readonly class ApplyCommand
{
    /**
     * @param (Closure(string): void)|null $authorizeMutation host authorization hook called with the label before any
     *                                                       non-dry-run write; it must throw to refuse the write
     */
    public function __construct(
        private string $projectRoot,
        private EditEngine $engine = new EditEngine(),
        private ?Closure $authorizeMutation = null,
    ) {
    }

    /** @param list<string> $tokens */
    public function run(array $tokens): int
    {
        if ($tokens === [] || in_array($tokens[0], ['help', '--help', '-h'], true)) {
            return $this->help();
        }

        try {
            $request = $this->parse($tokens);
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

            echo "Refactor execution bundle prepared: {$receipt->bundleDirectory}\n";
            echo '- plan: ' . ($receipt->planType !== '' ? $receipt->planType : 'unknown') . "\n";
            echo '- target: ' . ($receipt->targetId !== '' ? $receipt->targetId : 'unknown') . "\n";
            echo "- status: {$receipt->status}\n";
            echo "- execution: {$receipt->receiptPath}\n";

            return $receipt->succeeded() ? 0 : 1;
        } catch (Throwable $exception) {
            fwrite(STDERR, '[ERROR] ' . $exception->getMessage() . "\n");

            return 1;
        }
    }

    /**
     * @param list<string> $tokens
     * @return array{
     *     task_id: string,
     *     plan: string,
     *     map_index: string,
     *     map_root: string,
     *     output_directory: string,
     *     dry_run: bool
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
                    throw new InvalidArgumentException('Unexpected refactor argument: ' . $token);
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
                    throw new InvalidArgumentException('Missing value for refactor option: --' . $name);
                }
                ++$index;
            }
            if (!in_array($name, ['task', 'map-index', 'map-root', 'output-dir'], true)) {
                throw new InvalidArgumentException('Unknown refactor option: --' . $name);
            }
            if ($value === '' || isset($values[$name])) {
                throw new InvalidArgumentException('Invalid or duplicate refactor option: --' . $name);
            }
            $values[$name] = $value;
        }

        if (!is_string($plan) || trim($plan) === '') {
            throw new InvalidArgumentException('agent-edit apply requires exactly one plan JSON/TOON path.');
        }
        $planPath = $this->existingFile($root, $plan, 'refactor plan');
        $taskId = trim($values['task'] ?? '');
        if ($taskId === '') {
            $planBytes = file_get_contents($planPath);
            if (!is_string($planBytes)) {
                throw new RuntimeException('Unable to read refactor plan: ' . $plan);
            }
            $taskId = 'plan-' . substr(hash('sha256', $planBytes), 0, 12);
        }
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]*\z/', $taskId) !== 1 || str_contains($taskId, '..')) {
            throw new InvalidArgumentException('agent-edit apply requires a valid --task label.');
        }

        $mapRoot = $this->existingDirectory($root, $values['map-root'] ?? $root, 'map root');
        $mapIndex = $this->existingFile($root, $values['map-index'] ?? MapArtifactPaths::forProject($root)->indexJson(), 'map index');
        $output = $this->resolvePath($root, $values['output-dir'] ?? '.agent-edit/receipts/' . $taskId);

        return [
            'task_id' => $taskId,
            'plan' => $planPath,
            'map_index' => $mapIndex,
            'map_root' => $mapRoot,
            'output_directory' => $output,
            'dry_run' => $dryRun,
        ];
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
  agent-edit apply PLAN [options]

Consumes one safe versioned agent-map plan through agent-edit's deterministic mutation boundary.
The fixed allowlist covers the six rename-plan contracts, class_move_plan@1.0,
method_move_plan@1.0, method_removal_plan@1.0, property_removal_plan@1.0,
and class_constant_removal_plan@1.0.
Each owner family keeps its own wire decoder and semantic invariants; arbitrary edit plans and Rector
execution remain rejected.

Options:
  --task LABEL         Receipt label. Default: plan-<plan sha256 prefix>.
  --map-index PATH     Current agent-map JSON/TOON. Default: .agent-map/php-symbols.json
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
