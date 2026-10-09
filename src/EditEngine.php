<?php

declare(strict_types=1);

namespace voku\AgentEdit;

use HelgeSverre\Toon\Toon;
use InvalidArgumentException;
use JsonException;
use RuntimeException;
use Throwable;
use voku\AgentEdit\Apply\EditResult;
use voku\AgentEdit\Apply\MutationLock;
use voku\AgentEdit\Apply\PlanApplier;
use voku\AgentEdit\Apply\WorkingTreeSnapshotter;
use voku\AgentEdit\Capability\CapabilityRegistry;
use voku\AgentEdit\Capability\PlanCapability;
use voku\AgentEdit\Receipt\ApplyRequest;
use voku\AgentEdit\Receipt\EditReceipt;
use voku\AgentEdit\Receipt\ReceiptNotPersistedException;
use voku\AgentEdit\Verify\BundleVerifier;
use voku\AgentEdit\Verify\ImportResidueCheck;
use voku\AgentEdit\Verify\MapManifestEvidence;
use voku\AgentEdit\Verify\ResidueCheck;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\IndexReader;
use voku\AgentMap\MapArtifactPaths;

/**
 * The single public authority for deterministic, evidence-backed source mutation: preflight, apply (with receipt)
 * and verify. The CLI is only an argument/print adapter over this class.
 *
 * It never decides whether a task may mutate: hosts authorize before calling `apply*()` (or pass
 * `ApplyRequest::$authorizeMutation`). Plan types and contract versions are routed exclusively through
 * `CapabilityRegistry`; anything it does not list is rejected before any source is read.
 */
final readonly class EditEngine
{
    public const RECEIPT_FILE = 'execution.json';
    public const VERIFICATION_FILE = 'verification-result.json';

    /**
     * @param array<class-string<PlanApplier>, PlanApplier> $applierOverrides test seams keyed by applier class
     * @param array<class-string<BundleVerifier>, BundleVerifier> $verifierOverrides
     */
    public function __construct(
        private CapabilityRegistry $capabilities = new CapabilityRegistry(),
        private MutationLock $mutationLock = new MutationLock(),
        private WorkingTreeSnapshotter $snapshotter = new WorkingTreeSnapshotter(),
        private IndexReader $reader = new IndexReader(),
        private array $applierOverrides = [],
        private array $verifierOverrides = [],
    ) {
    }

    public function capabilities(): CapabilityRegistry
    {
        return $this->capabilities;
    }

    /**
     * Validates the complete plan against current source and Map evidence; nothing is written.
     *
     * @param array<string, mixed> $plan decoded plan document
     * @return array{files: array<string, string>, final_paths: array<string, string>, source_hashes: array<string, string>, plan_type: string, edit_count: int, move_count: int, deletion_count?: int}
     */
    public function preflight(array $plan, AgentMapIndex $map, string $repositoryRoot): array
    {
        return $this->applier($plan)->preflight($plan, $map, $repositoryRoot);
    }

    /**
     * Applies the plan transactionally under the project mutation lock: every source is staged, syntax-checked and
     * published together, and restored on any failure. Does not write a receipt; see `applyWithReceipt()`.
     *
     * @param array<string, mixed> $plan decoded plan document
     */
    public function apply(array $plan, AgentMapIndex $map, string $repositoryRoot): EditResult
    {
        $applier = $this->applier($plan);

        return $this->mutationLock->synchronized(
            $repositoryRoot,
            static fn (): EditResult => $applier->apply($plan, $map, $repositoryRoot),
        );
    }

    /**
     * File-based preflight/apply that also persists the receipt bundle (`execution.json`) with independently
     * observed changed files. A dry run validates and records a `prepared` receipt without writing source.
     */
    public function applyWithReceipt(ApplyRequest $request): EditReceipt
    {
        // A refusal that produces no receipt (dry-run preflight refusal, unknown plan type, host authorization refusal)
        // must not leave an empty bundle behind: a host that counts every bundle directory of a task would read the
        // empty one as a missing verification result and block a close that never edited anything.
        $created = $this->createDirectories($request->outputDirectory);
        try {
            return $this->applyAndPersist($request);
        } catch (ReceiptNotPersistedException $exception) {
            // The edits are already published: the bundle directory is the only trace a host can discover.
            throw $exception;
        } catch (Throwable $exception) {
            $this->removeEmptyDirectories($created);

            throw $exception;
        }
    }

    private function applyAndPersist(ApplyRequest $request): EditReceipt
    {
        $planRaw = file_get_contents($request->planPath);
        if (!is_string($planRaw)) {
            throw new RuntimeException('Unable to read refactor plan: ' . $request->planPath);
        }
        $plan = $this->decodePlan($request->planPath, $planRaw);
        $capability = $this->capability($plan);

        $map = $this->reader->read($request->mapIndexPath);
        $mapIndexHash = hash_file('sha256', $request->mapIndexPath);
        if (!is_string($mapIndexHash)) {
            throw new RuntimeException('Unable to hash agent-map index: ' . $request->mapIndexPath);
        }
        $applier = $this->applier($plan);
        $snapshotter = $this->snapshotter;
        $before = null;
        $after = null;
        $failure = null;
        $manifestBefore = null;

        if ($request->dryRun) {
            $before = $snapshotter->capture($request->repositoryRoot);
            $prepared = $applier->preflight($plan, $map, $request->mapRoot);
            $result = new EditResult(
                status: 'prepared',
                exitCode: 0,
                stdout: sprintf(
                    "%s validated %d edit(s), %d move(s) and %d deletion(s); no source was changed.\n",
                    $prepared['plan_type'],
                    $prepared['edit_count'],
                    $prepared['move_count'],
                    $prepared['deletion_count'] ?? 0,
                ),
            );
        } else {
            if ($request->authorizeMutation !== null) {
                ($request->authorizeMutation)($request->label);
            }
            // Observe changed files inside the lock: edits another process makes while this one waits for the lock
            // must not be attributed to this plan.
            try {
                $result = $this->mutationLock->synchronized(
                    $request->repositoryRoot,
                    static function () use ($applier, $plan, $map, $request, $snapshotter, &$before, &$after, &$manifestBefore): EditResult {
                        $before = $snapshotter->capture($request->repositoryRoot);
                        try {
                            if (!$before->available) {
                                $prepared = $applier->preflight($plan, $map, $request->mapRoot);
                                $root = realpath($request->mapRoot);
                                if (!is_string($root)) {
                                    throw new RuntimeException('Unable to resolve refactor map root for observation.');
                                }
                                $root = rtrim(str_replace('\\', '/', $root), '/');
                                $publicationPaths = [];
                                foreach ($prepared['final_paths'] as $source => $final) {
                                    if ($source !== $final) {
                                        $publicationPaths[] = substr(str_replace('\\', '/', $final), strlen($root) + 1);
                                    }
                                }
                                $manifestBefore = MapManifestEvidence::capture($map, $request->mapRoot, $publicationPaths);
                            }
                            $result = $applier->apply($plan, $map, $request->mapRoot);
                        } catch (Throwable $exception) {
                            $after = $snapshotter->capture($request->repositoryRoot);
                            throw $exception;
                        }
                        $after = $snapshotter->capture($request->repositoryRoot);

                        return $result;
                    },
                );
            } catch (Throwable $exception) {
                if ($before === null || $after === null) {
                    throw $exception;
                }
                $result = new EditResult(
                    status: 'runner_failed',
                    exitCode: 1,
                    stderr: $exception->getMessage(),
                );
                $failure = $exception;
            }
        }
        $after ??= $snapshotter->capture($request->repositoryRoot);
        if ($before === null) {
            throw new RuntimeException('Refactor execution completed without a working-tree observation.');
        }
        $receiptPath = $request->outputDirectory . '/' . self::RECEIPT_FILE;
        $changedFiles = $after->changedPathsSince($before);
        try {
            $observed = [
                'changed_files' => $changedFiles,
                'changed_files_source' => $before->available && $after->available ? 'git_status_diff' : 'unavailable',
            ];
            if ($manifestBefore !== null) {
                $changedFiles = MapManifestEvidence::changedSince($manifestBefore, $request->mapRoot);
                $encoded = MapManifestEvidence::encode($manifestBefore);
                $this->writeAtomically($request->outputDirectory . '/' . MapManifestEvidence::FILE, $encoded);
                $observed = [
                    'changed_files' => $changedFiles,
                    'changed_files_source' => MapManifestEvidence::SOURCE,
                    'scope_evidence' => MapManifestEvidence::reference($encoded),
                ];
            }
            $this->writeAtomically($receiptPath, $this->json([
                'schema_version' => '1.0',
                'status' => $result->status,
                'task_id' => $request->label,
                'plan' => [
                    'path' => $request->planPath,
                    'sha256' => 'sha256:' . hash('sha256', $planRaw),
                    'type' => $plan['type'] ?? null,
                    'contract_version' => $plan['contract_version'] ?? null,
                    'target_id' => $plan['target_id'] ?? null,
                ],
                'map_digest' => $map->mapDigest(),
                'map_index_sha256' => 'sha256:' . $mapIndexHash,
                'runner' => [
                    'name' => $capability->runner,
                    'exit_code' => $result->exitCode,
                    'dry_run' => $request->dryRun,
                    'model_input_tokens' => 0,
                    'model_tool_calls' => 0,
                ],
            ] + $observed));
        } catch (Throwable $writeFailure) {
            // The apply outcome stays the primary signal: a failed attempt's real error is never replaced by the
            // receipt problem, and a published edit without a receipt is reported as exactly that.
            if ($failure !== null) {
                throw $failure;
            }
            if (!$request->dryRun && $result->status === 'runner_succeeded') {
                throw new ReceiptNotPersistedException($changedFiles, $request->outputDirectory, $writeFailure);
            }

            throw $writeFailure;
        }

        $receipt = new EditReceipt(
            $result->status,
            $result->exitCode,
            $request->outputDirectory,
            $receiptPath,
            (string) ($plan['type'] ?? ''),
            (string) ($plan['target_id'] ?? ''),
        );

        if ($failure !== null) {
            throw $failure;
        }

        return $receipt;
    }

    /**
     * Verifies one applied receipt bundle against current source and a refreshed Map, and persists
     * `verification-result.json` into the bundle. All inputs must stay inside the repository root.
     *
     * @return array<string, mixed> the verification result document
     */
    public function verify(string $repositoryRoot, string $bundle, ?string $mapIndex = null, string $mapRoot = '.', ?string $acceptResidue = null): array
    {
        $root = realpath($repositoryRoot);
        if (!is_string($root)) {
            throw new RuntimeException('Project root not found: ' . $repositoryRoot);
        }
        $bundlePath = $this->insideExisting($root, $bundle, 'bundle', true);
        // A failed attempt must never leave an earlier successful verdict available to bundle consumers.
        $verificationPath = $bundlePath . '/' . self::VERIFICATION_FILE;
        if ((file_exists($verificationPath) || is_link($verificationPath)) && !@unlink($verificationPath)) {
            throw new RuntimeException('Unable to invalidate previous refactor verification result.');
        }
        $mapIndexPath = $this->insideExisting($root, $mapIndex ?? MapArtifactPaths::forProject($root)->indexJson(), 'map index', false);
        $mapRootPath = $this->insideExisting($root, $mapRoot, 'map root', true);

        $receipt = $this->readReceipt($bundlePath . '/' . self::RECEIPT_FILE);
        $runner = $receipt['runner']['name'] ?? null;
        $capability = is_string($runner) ? $this->capabilities->findByRunner($runner) : null;
        if ($capability === null) {
            throw new RuntimeException('Refactor execution runner identity is not an executable agent-edit capability.');
        }

        $verifier = $this->verifierOverrides[$capability->verifier] ?? new ($capability->verifier)();
        $result = $verifier->verify($bundlePath, $mapIndexPath, $mapRootPath);
        $result = (new ResidueCheck())->apply($result, $this->boundPlan($receipt), $mapRootPath, $acceptResidue);
        $result = (new ImportResidueCheck())->apply($result, $this->boundPlan($receipt), $mapRootPath, $acceptResidue);
        $this->writeAtomically($verificationPath, $this->json($result));

        return $result;
    }

    /**
     * The immutable plan the receipt is bound to; the plan-type verifier has already proven its hash.
     *
     * @param array<string, mixed> $receipt
     * @return array<string, mixed>
     */
    private function boundPlan(array $receipt): array
    {
        $path = $receipt['plan']['path'] ?? null;
        $raw = is_string($path) ? @file_get_contents($path) : false;

        return is_string($path) && is_string($raw) ? $this->decodePlan($path, $raw) : [];
    }

    /** @param array<string, mixed> $plan */
    private function capability(array $plan): PlanCapability
    {
        $type = $plan['type'] ?? null;
        $version = $plan['contract_version'] ?? null;
        $capability = is_string($type) ? $this->capabilities->find($type) : null;
        if ($capability === null || !is_string($version) || !in_array($version, $capability->contractVersions, true)) {
            throw new InvalidArgumentException('Unsupported plan type or contract version; see `agent-edit capabilities`.');
        }

        return $capability;
    }

    /** @param array<string, mixed> $plan */
    private function applier(array $plan): PlanApplier
    {
        $class = $this->capability($plan)->applier;

        return $this->applierOverrides[$class] ?? new $class();
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

    /** @return array<string, mixed> */
    private function readReceipt(string $path): array
    {
        $raw = @file_get_contents($path);
        if (!is_string($raw)) {
            throw new RuntimeException('Unable to read refactor execution evidence.');
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Malformed refactor execution evidence: ' . $exception->getMessage(), 0, $exception);
        }
        if (!is_array($decoded)) {
            throw new RuntimeException('Refactor execution evidence must decode to an object.');
        }

        return $decoded;
    }

    private function insideExisting(string $root, string $path, string $label, bool $directory): string
    {
        if ($path === '') {
            throw new RuntimeException('Refactor verify requires ' . $label . '.');
        }
        $candidate = str_starts_with($path, '/') ? $path : $root . '/' . $path;
        $real = realpath($candidate);
        if (!is_string($real) || ($directory ? !is_dir($real) : !is_file($real))) {
            throw new RuntimeException('Refactor verify ' . $label . ' not found: ' . $path);
        }
        $root = rtrim(str_replace('\\', '/', $root), '/');
        $real = str_replace('\\', '/', $real);
        if ($real !== $root && !str_starts_with($real, $root . '/')) {
            throw new RuntimeException('Refactor verify ' . $label . ' escapes the project root.');
        }

        return $real;
    }

    private function ensureDirectory(string $directory): void
    {
        $this->createDirectories($directory);
    }

    /**
     * Creates the directory (and missing parents) and reports exactly which ones this call created, deepest first.
     *
     * @return list<string>
     */
    private function createDirectories(string $directory): array
    {
        $missing = [];
        for ($path = $directory; $path !== '' && !is_dir($path); $path = dirname($path)) {
            $missing[] = $path;
            if (dirname($path) === $path) {
                break;
            }
        }
        if ($missing !== [] && !@mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create refactor evidence directory: ' . $directory);
        }

        return $missing;
    }

    /** @param list<string> $directories deepest first; only directories that are still empty are removed */
    private function removeEmptyDirectories(array $directories): void
    {
        foreach ($directories as $directory) {
            if (!is_dir($directory) || (new \FilesystemIterator($directory))->valid()) {
                return;
            }
            if (!@rmdir($directory)) {
                return;
            }
        }
    }

    /** Atomically writes one evidence file by renaming a same-directory staging file. */
    private function writeAtomically(string $path, string $content): void
    {
        $this->ensureDirectory(dirname($path));
        $temporary = $path . '.tmp-' . getmypid();
        if (@file_put_contents($temporary, $content) === false) {
            throw new RuntimeException('Unable to write refactor evidence: ' . $temporary);
        }
        if (!@rename($temporary, $path)) {
            if (is_file($temporary) && !unlink($temporary)) {
                throw new RuntimeException('Unable to publish refactor evidence and cleanup temporary file: ' . $path);
            }
            throw new RuntimeException('Unable to publish refactor evidence: ' . $path);
        }
    }

    /** @param array<string, mixed> $payload */
    private function json(array $payload): string
    {
        try {
            return json_encode(
                $payload,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            ) . "\n";
        } catch (JsonException $exception) {
            throw new RuntimeException('Unable to encode refactor evidence JSON: ' . $exception->getMessage(), 0, $exception);
        }
    }
}
