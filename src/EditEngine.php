<?php

declare(strict_types=1);

namespace voku\AgentEdit;

use Closure;
use HelgeSverre\Toon\Toon;
use InvalidArgumentException;
use JsonException;
use RuntimeException;
use voku\AgentEdit\Failure\EditFailure;
use voku\AgentEdit\Failure\FailureCode;
use voku\AgentEdit\Failure\InvalidInputFailure;
use voku\AgentEdit\Preview\ChangePreview;
use Throwable;
use voku\AgentEdit\Apply\EditResult;
use voku\AgentEdit\Apply\FileOperation;
use voku\AgentEdit\Apply\FileTransaction;
use voku\AgentEdit\Apply\MaplessPlanApplier;
use voku\AgentEdit\Apply\MutationLock;
use voku\AgentEdit\Apply\PlanApplier;
use voku\AgentEdit\Apply\SourceEditPlanApplier;
use voku\AgentEdit\Apply\SourceEditPreparation;
use voku\AgentEdit\Apply\WorkingTreeSnapshot;
use voku\AgentEdit\Apply\WorkingTreeSnapshotter;
use voku\AgentEdit\Capability\CapabilityRegistry;
use voku\AgentEdit\Capability\PlanCapability;
use voku\AgentEdit\Receipt\ApplyRequest;
use voku\AgentEdit\Plan\SourceEditPlanDocument;
use voku\AgentEdit\Receipt\EditReceipt;
use voku\AgentEdit\Receipt\PublicationJournal;
use voku\AgentEdit\Receipt\ReceiptNotPersistedException;
use voku\AgentEdit\Verify\BundleVerifier;
use voku\AgentEdit\Verify\ImportResidueCheck;
use voku\AgentEdit\Verify\MapManifestEvidence;
use voku\AgentEdit\Verify\MaplessBundleVerifier;
use voku\AgentEdit\Verify\ResidueCheck;
use voku\AgentEdit\Verify\SourceEditVerifier;
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
    public const UNDO_RESULT_FILE = SourceEditVerifier::UNDO_RESULT_FILE;
    public const RECOVERY_RESULT_FILE = 'recovery-result.json';

    /**
     * @param array<class-string<PlanApplier|MaplessPlanApplier>, PlanApplier|MaplessPlanApplier> $applierOverrides test seams keyed by applier class
     * @param array<class-string<BundleVerifier|MaplessBundleVerifier>, BundleVerifier|MaplessBundleVerifier> $verifierOverrides
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
     * @param AgentMapIndex|null $map required for every Map plan; `source_edit_plan` needs none
     * @return array{files: array<string, string>, final_paths: array<string, string>, source_hashes: array<string, string>, plan_type: string, edit_count: int, move_count: int, deletion_count?: int, creation_count?: int}
     */
    public function preflight(array $plan, ?AgentMapIndex $map, string $repositoryRoot): array
    {
        $applier = $this->applier($plan);
        if ($applier instanceof MaplessPlanApplier) {
            return $applier->preflight($plan, $repositoryRoot);
        }

        return $applier->preflight($plan, $this->requireMap($map), $repositoryRoot);
    }

    /**
     * Applies the plan transactionally under the project mutation lock: every source is staged, syntax-checked and
     * published together, and restored on any failure. Does not write a receipt; see `applyWithReceipt()`.
     *
     * @param array<string, mixed> $plan decoded plan document
     */
    public function apply(array $plan, ?AgentMapIndex $map, string $repositoryRoot): EditResult
    {
        $applier = $this->applier($plan);
        if ($applier instanceof MaplessPlanApplier) {
            return $this->mutationLock->synchronized(
                $repositoryRoot,
                static fn (): EditResult => $applier->apply($plan, $repositoryRoot),
            );
        }
        $map = $this->requireMap($map);

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
            // One run per bundle from claim to receipt: without it, a second run could claim the bundle before the
            // first one wrote its receipt and then overwrite a successful receipt with its own failure.
            return $this->withBundleLock($request->outputDirectory, fn (): EditReceipt => $this->applyAndPersist($request));
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
        $this->claimBundle($request->outputDirectory);
        if (!$capability->requiresMap) {
            return $this->applyDirectAndPersist($request, $plan, $planRaw, $capability);
        }
        if ($request->mapIndexPath === null) {
            throw new InvalidArgumentException('This plan type needs an agent-map index; pass --map-index.');
        }

        $map = $this->reader->read($request->mapIndexPath);
        $mapIndexHash = hash_file('sha256', $request->mapIndexPath);
        if (!is_string($mapIndexHash)) {
            throw new RuntimeException('Unable to hash agent-map index: ' . $request->mapIndexPath);
        }
        $applier = $this->mapApplier($plan);
        $snapshotter = $this->snapshotter;
        $before = null;
        $after = null;
        $failure = null;
        $manifestBefore = null;
        $preview = ['changes' => [], 'diff' => ''];
        $receiptTemplate = $this->receiptTemplate($request, $plan, $planRaw, $capability, $map->mapDigest(), 'sha256:' . $mapIndexHash);

        if ($request->dryRun) {
            $before = $snapshotter->capture($request->repositoryRoot);
            $prepared = $applier->preflight($plan, $map, $request->mapRoot);
            $preview = ChangePreview::fromPreflight($prepared, $request->mapRoot);
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
                    static function () use ($applier, $plan, $map, $request, $snapshotter, $receiptTemplate, &$before, &$after, &$manifestBefore): EditResult {
                        $before = $snapshotter->capture($request->repositoryRoot);
                        try {
                            $prepared = $applier->preflight($plan, $map, $request->mapRoot);
                            if (!$before->available) {
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
                                // A created file is absent from the old Map; its absence is observed before it appears.
                                foreach (array_keys($prepared['files']) as $candidate) {
                                    if (!isset($prepared['source_hashes'][$candidate]) && !is_file($candidate)) {
                                        $publicationPaths[] = substr(str_replace('\\', '/', $candidate), strlen($root) + 1);
                                    }
                                }
                                $manifestBefore = MapManifestEvidence::capture($map, $request->mapRoot, $publicationPaths);
                            }
                            // Write-ahead: from here on a killed process or a lost receipt leaves a journal to recover from.
                            PublicationJournal::start($request, $receiptTemplate, $prepared, $before, $manifestBefore, null);
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
        $changedFiles = $this->withoutBundlePaths($after->changedPathsSince($before), $request->repositoryRoot, $request->outputDirectory);
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
            ] + $observed + ($request->dryRun ? ['planned_changes' => $preview['changes']] : [])));
        } catch (Throwable $writeFailure) {
            // The apply outcome stays the primary signal: a failed attempt's real error is never replaced by the
            // receipt problem, and a published edit without a receipt is reported as exactly that.
            if ($failure !== null) {
                throw $this->withPendingRecovery($failure, $request->outputDirectory);
            }
            if (!$request->dryRun && $result->status === 'runner_succeeded') {
                throw new ReceiptNotPersistedException($changedFiles, $request->outputDirectory, $writeFailure);
            }

            throw $writeFailure;
        }
        $this->settleJournal($request->outputDirectory, $request->repositoryRoot, $result->status);

        $receipt = new EditReceipt(
            $result->status,
            $result->exitCode,
            $request->outputDirectory,
            $receiptPath,
            (string) ($plan['type'] ?? ''),
            (string) ($plan['target_id'] ?? ''),
            $preview['changes'],
            $preview['diff'],
            $changedFiles,
        );

        if ($failure !== null) {
            throw $this->withPendingRecovery($failure, $request->outputDirectory);
        }
        $this->assertSettled($request, $result->status);

        return $receipt;
    }

    /**
     * Receipt flow for plan families that need no Map: the same lock, authorization, before/after observation and
     * atomic receipt as Map plans, plus the pre-images `undo()` needs.
     *
     * @param array<string, mixed> $plan
     */
    private function applyDirectAndPersist(ApplyRequest $request, array $plan, string $planRaw, PlanCapability $capability): EditReceipt
    {
        $applier = $this->applier($plan);
        if (!$applier instanceof MaplessPlanApplier) {
            throw new RuntimeException('Plan capability is not a Map-free applier.');
        }
        $snapshotter = $this->snapshotter;
        $before = null;
        $after = null;
        $failure = null;
        $preview = ['changes' => [], 'diff' => ''];
        $receiptTemplate = $this->receiptTemplate($request, $plan, $planRaw, $capability, null, null);
        if ($request->requireSourceHashes) {
            self::assertSourceHashes(SourceEditPlanDocument::fromArray($plan));
        }

        if ($request->dryRun) {
            $before = $snapshotter->capture($request->repositoryRoot);
            $prepared = $applier->preflight($plan, $request->repositoryRoot);
            $preview = ChangePreview::fromPreflight($prepared, $request->repositoryRoot);
            $result = new EditResult(
                status: 'prepared',
                exitCode: 0,
                stdout: sprintf(
                    "%s validated %d edit(s) and %d creation(s) across %d file(s); no source was changed.\n",
                    $prepared['plan_type'],
                    $prepared['edit_count'],
                    $prepared['creation_count'],
                    count($prepared['files']),
                ),
            );
        } else {
            // Pure and write-free: an edit that cannot be proven (ambiguous anchor, stale text, API change) is refused
            // here, before authorization, and leaves no receipt behind.
            $applier->preflight($plan, $request->repositoryRoot);
            if ($request->authorizeMutation !== null) {
                ($request->authorizeMutation)($request->label);
            }
            try {
                $result = $this->mutationLock->synchronized(
                    $request->repositoryRoot,
                    static function () use ($applier, $plan, $request, $snapshotter, $receiptTemplate, &$before, &$after): EditResult {
                        $before = $snapshotter->capture($request->repositoryRoot);
                        try {
                            if ($applier instanceof SourceEditPlanApplier) {
                                // One preparation under the lock: the journal describes exactly what is published, and the
                                // transaction re-proves its digests, so a concurrent change rolls back instead of diverging.
                                $preparation = $applier->prepare(SourceEditPlanDocument::fromArray($plan), $request->repositoryRoot);
                                PublicationJournal::start($request, $receiptTemplate, $applier->preflightOf($preparation, $request->repositoryRoot), $before, null, self::directJournalDetails($preparation));
                                $result = $applier->applyPrepared($preparation, $request->repositoryRoot);
                            } else {
                                PublicationJournal::start($request, $receiptTemplate, $applier->preflight($plan, $request->repositoryRoot), $before, null, null);
                                $result = $applier->apply($plan, $request->repositoryRoot);
                            }
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
                $result = new EditResult(status: 'runner_failed', exitCode: 1, stderr: $exception->getMessage());
                $failure = $exception;
            }
        }
        $after ??= $snapshotter->capture($request->repositoryRoot);
        if ($before === null) {
            throw new RuntimeException('Direct edit completed without a working-tree observation.');
        }

        $receiptPath = $request->outputDirectory . '/' . self::RECEIPT_FILE;
        $changedFiles = $this->withoutBundlePaths($after->changedPathsSince($before), $request->repositoryRoot, $request->outputDirectory);
        try {
            $direct = [];
            if ($result->status === 'runner_succeeded') {
                /** @var array<string, string> $preImages */
                $preImages = is_array($result->evidence['pre_images'] ?? null) ? $result->evidence['pre_images'] : [];
                $files = is_array($result->evidence['files'] ?? null) ? $result->evidence['files'] : [];
                $recorded = [];
                foreach ($files as $file) {
                    if (!is_array($file) || !is_string($file['path'] ?? null)) {
                        continue;
                    }
                    $preImage = null;
                    if (isset($preImages[$file['path']])) {
                        $preImage = 'before/' . $file['path'];
                        $preImageFile = $request->outputDirectory . '/' . $preImage;
                        // The journal already stored this pre-image privately; rewrite it only if it is missing or differs.
                        if (!is_file($preImageFile) || hash_file('sha256', $preImageFile) !== hash('sha256', $preImages[$file['path']])) {
                            PublicationJournal::durableWrite($preImageFile, $preImages[$file['path']], 0o600);
                        }
                    }
                    $recorded[] = $file + ['pre_image' => $preImage];
                }
                // `git status` cannot see ignored files; the verifier proves those by digest alone and says so.
                $paths = array_map(static fn (array $file): string => (string) $file['path'], $recorded);
                $direct = ['direct_edit' => [
                    'files' => $recorded,
                    'git_ignored' => $before->available ? $snapshotter->ignoredPaths($request->repositoryRoot, $paths) : [],
                    'acknowledged_api_changes' => $result->evidence['acknowledged_api_changes'] ?? [],
                ]];
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
                'map_digest' => null,
                'map_index_sha256' => null,
                'runner' => [
                    'name' => $capability->runner,
                    'exit_code' => $result->exitCode,
                    'dry_run' => $request->dryRun,
                    'model_input_tokens' => 0,
                    'model_tool_calls' => 0,
                ],
                'changed_files' => $changedFiles,
                'changed_files_source' => $before->available && $after->available ? 'git_status_diff' : 'unavailable',
            ] + $direct + ($request->dryRun ? ['planned_changes' => $preview['changes']] : [])));
        } catch (Throwable $writeFailure) {
            if ($failure !== null) {
                throw $this->withPendingRecovery($failure, $request->outputDirectory);
            }
            if (!$request->dryRun && $result->status === 'runner_succeeded') {
                throw new ReceiptNotPersistedException($changedFiles, $request->outputDirectory, $writeFailure);
            }

            throw $writeFailure;
        }
        $this->settleJournal($request->outputDirectory, $request->repositoryRoot, $result->status);

        $receipt = new EditReceipt(
            $result->status,
            $result->exitCode,
            $request->outputDirectory,
            $receiptPath,
            (string) ($plan['type'] ?? ''),
            (string) ($plan['target_id'] ?? ''),
            $preview['changes'],
            $preview['diff'],
            $changedFiles,
        );
        if ($failure !== null) {
            throw $this->withPendingRecovery($failure, $request->outputDirectory);
        }
        $this->assertSettled($request, $result->status);

        return $receipt;
    }

    /**
     * Restores the exact pre-edit bytes recorded in an applied `source_edit_plan` bundle, and removes the files that
     * edit created. Fails closed: every touched file must still have exactly the digest the edit published, so edits made
     * afterwards (by anyone) are never overwritten.
     *
     * @param (Closure(string): void)|null $authorizeMutation host authorization hook called immediately before restore
     * @return array<string, mixed> the undo result document (also persisted as `undo-result.json`)
     */
    public function undo(string $repositoryRoot, string $bundle, ?Closure $authorizeMutation = null): array
    {
        // Held from the pending-journal check through the restore and its result, so a concurrent apply into this
        // bundle cannot install a journal or a receipt in between (bundle lock first, then the project lock).
        return $this->withBundleLock($this->existingBundle($repositoryRoot, $bundle), fn (): array => $this->undoUnderBundleLock($repositoryRoot, $bundle, $authorizeMutation));
    }

    /**
     * @param (Closure(string): void)|null $authorizeMutation
     * @return array<string, mixed>
     */
    private function undoUnderBundleLock(string $repositoryRoot, string $bundle, ?Closure $authorizeMutation = null): array
    {
        $root = realpath($repositoryRoot);
        if (!is_string($root)) {
            throw new RuntimeException('Project root not found: ' . $repositoryRoot);
        }
        $bundlePath = $this->insideExisting($root, $bundle, 'bundle', true);
        $this->assertNoPendingPublication($bundlePath);
        $receipt = $this->readReceipt($bundlePath . '/' . self::RECEIPT_FILE);
        if (($receipt['runner']['name'] ?? null) !== 'source-edit-plan'
            || ($receipt['status'] ?? null) !== 'runner_succeeded'
            || ($receipt['runner']['dry_run'] ?? null) !== false
        ) {
            throw new RuntimeException('Only a successfully applied source_edit_plan bundle can be undone.');
        }
        if (is_file($bundlePath . '/' . self::UNDO_RESULT_FILE)) {
            throw new RuntimeException('This edit was already undone.');
        }
        $recorded = $receipt['direct_edit']['files'] ?? null;
        if (!is_array($recorded) || !array_is_list($recorded) || $recorded === []) {
            throw new RuntimeException('Bundle has no direct_edit file evidence to undo.');
        }

        $root = rtrim(str_replace('\\', '/', $root), '/');
        $operations = [];
        $restored = [];
        $removed = [];
        foreach ($recorded as $file) {
            if (!is_array($file) || !is_string($file['path'] ?? null) || !is_string($file['after_sha256'] ?? null)) {
                throw new RuntimeException('Bundle contains malformed direct_edit file evidence.');
            }
            $relative = $this->canonicalRelative($file['path']);
            $absolute = $root . '/' . $relative;
            $beforeSha = $file['before_sha256'] ?? null;
            if ($beforeSha === null) {
                $operations[] = FileOperation::delete($absolute, $file['after_sha256']);
                $removed[] = $relative;
                continue;
            }
            if (!is_string($beforeSha) || !is_string($file['pre_image'] ?? null)) {
                throw new RuntimeException('Bundle is missing the pre-image for ' . $relative . '.');
            }
            $preImage = $bundlePath . '/' . $this->canonicalRelative($file['pre_image']);
            $content = is_file($preImage) && !is_link($preImage) ? file_get_contents($preImage) : false;
            if (!is_string($content) || !hash_equals($beforeSha, hash('sha256', $content))) {
                throw new RuntimeException('Recorded pre-image of ' . $relative . ' is missing or was altered; refusing to restore it.');
            }
            $operations[] = FileOperation::write($absolute, $content, $file['after_sha256']);
            $restored[] = $relative;
        }

        $transaction = new FileTransaction();
        $transaction->preflight($operations, $root);
        if ($authorizeMutation !== null) {
            $authorizeMutation((string) ($receipt['task_id'] ?? ''));
        }
        $this->mutationLock->synchronized($root, static function () use ($transaction, $operations, $root): EditResult {
            $transaction->run($operations, $root);

            return new EditResult(status: 'runner_succeeded', exitCode: 0);
        });
        foreach ($removed as $relative) {
            for ($directory = dirname($root . '/' . $relative); $directory !== $root && str_starts_with($directory, $root . '/'); $directory = dirname($directory)) {
                if (!is_dir($directory) || (new \FilesystemIterator($directory))->valid() || !@rmdir($directory)) {
                    break;
                }
            }
        }

        $result = [
            'schema_version' => '1.0',
            'kind' => 'source_edit_plan_undo',
            'status' => 'undone',
            'task_id' => (string) ($receipt['task_id'] ?? ''),
            'restored' => $restored,
            'removed' => $removed,
        ];
        $this->invalidateVerification($bundlePath);
        $this->writeAtomically($bundlePath . '/' . self::UNDO_RESULT_FILE, $this->json($result));

        return $result;
    }

    /**
     * Resolves an unfinished publication recorded in a bundle's `publication-journal.json` (a lost receipt, an
     * incomplete rollback or a killed process). The tree is observed against the journal under the mutation lock:
     *
     * - `published`: every path has its published bytes. A receipt is written (unless one already records the
     *   success); its changed files are observed again by git (or the stored Map manifest), never taken from the
     *   journal, so `verify` still decides on independent evidence.
     * - `not_published`: every path still has its original bytes. A `runner_failed` receipt records that.
     * - `interrupted`: a mix. Every path is restored from the bundle's pre-images (all-or-nothing, like a failed apply)
     *   and a `runner_failed` receipt records the rollback.
     * - `conflict`: some path matches neither digest, so someone else changed it. Nothing is touched and the journal
     *   stays, so recovery can be retried once the conflict is resolved by hand.
     *
     * Publisher temporary files next to journaled paths are removed in every non-conflict case. Running it again
     * after success reports `nothing_to_recover`.
     *
     * @param (Closure(string): void)|null $authorizeMutation host authorization hook, called before anything is changed
     * @return array<string, mixed> the recovery result (also persisted as `recovery-result.json` unless it is a conflict)
     */
    public function recover(string $repositoryRoot, string $bundle, ?Closure $authorizeMutation = null): array
    {
        $root = realpath($repositoryRoot);
        if (!is_string($root)) {
            throw new RuntimeException('Project root not found: ' . $repositoryRoot);
        }
        $root = rtrim(str_replace('\\', '/', $root), '/');
        $bundlePath = $this->recoverableBundle($root, $bundle);

        return $this->withBundleLock($bundlePath, function () use ($root, $bundlePath, $authorizeMutation): array {
            $journal = PublicationJournal::read($bundlePath);
            if ($journal === null) {
                return $this->recoveryResult('nothing_to_recover', null, [], []);
            }
            /** @var list<array<string, mixed>> $entries */
            $entries = $journal['entries'];
            $taskId = (string) ($journal['receipt']['task_id'] ?? '');
            $receiptPath = $bundlePath . '/' . self::RECEIPT_FILE;
            $existing = is_file($receiptPath) ? $this->readReceipt($receiptPath) : null;

            $observed = PublicationJournal::observe($root, $entries);
            $conflict = $this->recoveryConflict($observed['verdict'], $existing);
            if ($conflict !== null) {
                return $this->recoveryResult('conflict', $taskId, $observed['files'], [], $conflict);
            }

            if ($authorizeMutation !== null) {
                $authorizeMutation($taskId);
            }
            $outcome = null;
            $this->mutationLock->synchronized($root, function () use ($root, $bundlePath, $journal, $entries, $observed, $existing, $receiptPath, $taskId, &$outcome): EditResult {
                // Observed again under the lock: nothing may have changed while the host was authorizing.
                $current = PublicationJournal::observe($root, $entries);
                if ($current !== $observed) {
                    throw new RuntimeException('Sources changed while recovery was being authorized; nothing was changed. Run recover again.');
                }
                $verdict = $current['verdict'];
                if ($verdict === 'interrupted') {
                    $this->rollBackFromJournal($root, $bundlePath, $entries, $current['files']);
                }
                $owned = PublicationJournal::ownedTemporaryFiles($root, $entries);
                foreach ($owned as $relative) {
                    if (!@unlink($root . '/' . $relative)) {
                        throw new RuntimeException('Unable to remove publisher temporary file: ' . $relative);
                    }
                }

                $existingSucceeded = ($existing['status'] ?? null) === 'runner_succeeded';
                // A rollback an earlier recovery completed (only its result file was lost) stays recorded as a rollback.
                // Only a rollback receipt written for this very journal counts: a reused bundle may hold an older one.
                $earlierRollback = ($existing['recovery']['rolled_back'] ?? null) === true && ($existing['status'] ?? null) === 'runner_failed'
                    && ($existing['recovery']['attempt'] ?? null) === $journal['attempt'];
                $status = match (true) {
                    $verdict === 'interrupted' => 'rolled_back',
                    $verdict === 'not_published' && $earlierRollback => 'rolled_back',
                    $verdict === 'unchanged' => $existingSucceeded ? 'published' : 'not_published',
                    default => $verdict,
                };
                $keepReceipt = ($status === 'published' && $existingSucceeded) || ($verdict === 'not_published' && $earlierRollback);
                if ($keepReceipt && $status === 'published') {
                    $this->restoreBoundManifest($bundlePath, $journal, $existing);
                }
                if (!$keepReceipt) {
                    $this->invalidateVerification($bundlePath);
                    $this->writeAtomically($receiptPath, $this->json($this->recoveredReceipt($root, $bundlePath, $journal, $verdict, $status === 'published')));
                }
                $outcome = $this->recoveryResult($status, $taskId, $current['files'], $owned);
                $this->writeAtomically($bundlePath . '/' . self::RECOVERY_RESULT_FILE, $this->json($outcome));
                PublicationJournal::remove($bundlePath);

                return new EditResult(status: 'runner_succeeded', exitCode: 0);
            });

            return (array) $outcome;
        });
    }

    /**
     * Why recovery must not touch anything, or null when it may proceed: a path matches neither recorded digest
     * (`unexpected_file`), or the bundle already holds a success receipt while the sources are not in their published
     * state (`receipt_claims_publication`; put the published bytes back, or remove the receipt deliberately).
     *
     * @param array<string, mixed>|null $existing
     */
    private function recoveryConflict(string $verdict, ?array $existing): ?string
    {
        if ($verdict === 'conflict') {
            return 'unexpected_file';
        }
        if (($existing['status'] ?? null) === 'runner_succeeded' && !in_array($verdict, ['published', 'unchanged'], true)) {
            return 'receipt_claims_publication';
        }

        return null;
    }

    /**
     * The bundle to recover. `applyWithReceipt()` accepts any output directory and names it in its recovery message,
     * so an existing bundle outside the project is accepted here too (bundle consumers that need the project-relative
     * contract still refuse it).
     */
    private function recoverableBundle(string $root, string $bundle): string
    {
        $candidate = str_starts_with(str_replace('\\', '/', $bundle), '/') || preg_match('~^[A-Za-z]:[\\\\/]~', $bundle) === 1
            ? $bundle
            : $root . '/' . $bundle;
        $real = realpath($candidate);
        if (!is_string($real) || !is_dir($real)) {
            throw new RuntimeException('Bundle not found: ' . $bundle);
        }
        $real = rtrim(str_replace('\\', '/', $real), '/');

        return str_starts_with($real . '/', $root . '/') ? $this->insideExisting($root, $bundle, 'bundle', true) : $real;
    }

    /**
     * Restores every journaled path to its recorded original state in one transaction. Exact recorded bytes are put
     * back, so they are not syntax-checked again (the original may itself have been the syntax error being repaired).
     *
     * @param list<array<string, mixed>> $entries
     * @param list<array{path: string, state: string}> $states
     */
    private function rollBackFromJournal(string $root, string $bundlePath, array $entries, array $states): void
    {
        $stateByPath = array_column($states, 'state', 'path');
        $operations = [];
        foreach ($entries as $entry) {
            $relative = PublicationJournal::canonical((string) $entry['path']);
            $state = $stateByPath[$relative] ?? PublicationJournal::STATE_UNEXPECTED;
            if ($state === PublicationJournal::STATE_BEFORE || $state === PublicationJournal::STATE_UNCHANGED) {
                continue;
            }
            $absolute = $root . '/' . $relative;
            $before = is_string($entry['before_sha256'] ?? null) ? $entry['before_sha256'] : null;
            $after = is_string($entry['after_sha256'] ?? null) ? $entry['after_sha256'] : null;
            if ($before === null) {
                $operations[] = FileOperation::delete($absolute, (string) $after);
                continue;
            }
            $preImagePath = $bundlePath . '/' . PublicationJournal::canonical((string) ($entry['pre_image'] ?? ''));
            $content = is_file($preImagePath) && !is_link($preImagePath) ? file_get_contents($preImagePath) : false;
            if (!is_string($content) || !hash_equals($before, hash('sha256', $content))) {
                throw new RuntimeException('Recorded pre-image of ' . $relative . ' is missing or was altered; refusing to restore it.');
            }
            $mode = is_int($entry['mode'] ?? null) ? $entry['mode'] : null;
            $operations[] = FileOperation::write($absolute, $content, $state === PublicationJournal::STATE_AFTER ? $after : null, $mode);
        }
        if ($operations === []) {
            return;
        }
        $restoreExactBytes = static fn (string $path): array => ['exit_code' => 0, 'stdout' => '', 'stderr' => ''];
        (new FileTransaction(lintOperation: $restoreExactBytes))->run($operations, $root);
    }

    /**
     * @param array<string, mixed> $journal
     * @return array<string, mixed>
     */
    private function recoveredReceipt(string $root, string $bundlePath, array $journal, string $verdict, bool $succeeded): array
    {
        /** @var array<string, mixed> $receipt */
        $receipt = is_array($journal['receipt'] ?? null) ? $journal['receipt'] : [];
        $receipt['status'] = $succeeded ? 'runner_succeeded' : 'runner_failed';
        $receipt['runner'] = (is_array($receipt['runner'] ?? null) ? $receipt['runner'] : []) + ['exit_code' => null];
        $receipt['runner']['exit_code'] = $succeeded ? 0 : 1;

        // Changed files are observed again, exactly as a normal run observes them; the journal's own path list is
        // never used as evidence.
        $observation = is_array($journal['observation'] ?? null) ? $journal['observation'] : [];
        $git = is_array($observation['git'] ?? null) ? $observation['git'] : null;
        $manifest = is_array($observation['map_manifest'] ?? null) ? $observation['map_manifest'] : null;
        if ($git !== null) {
            $entries = PublicationJournal::decodeSnapshotEntries($git['entries'] ?? []);
            $before = new WorkingTreeSnapshot(true, is_string($git['head'] ?? null) ? $git['head'] : null, $entries);
            $after = $this->snapshotter->capture($root);
            $receipt['changed_files'] = $this->withoutBundlePaths($after->changedPathsSince($before), $root, $bundlePath);
            $receipt['changed_files_source'] = $after->available ? 'git_status_diff' : 'unavailable';
        } elseif ($manifest !== null && is_string($observation['map_root'] ?? null)) {
            /** @var array<string, string> $manifest */
            $encoded = MapManifestEvidence::encode($manifest);
            // The receipt binds the stored manifest by digest; restore it from the journal in case it was lost.
            PublicationJournal::durableWrite($bundlePath . '/' . MapManifestEvidence::FILE, $encoded);
            $receipt['changed_files'] = MapManifestEvidence::changedSince($manifest, $observation['map_root']);
            $receipt['changed_files_source'] = MapManifestEvidence::SOURCE;
            $receipt['scope_evidence'] = MapManifestEvidence::reference($encoded);
        } else {
            $receipt['changed_files'] = [];
            $receipt['changed_files_source'] = 'unavailable';
        }

        if ($succeeded && is_array($journal['direct_edit'] ?? null)) {
            $direct = $journal['direct_edit'];
            $files = [];
            foreach (is_array($direct['files'] ?? null) ? $direct['files'] : [] as $file) {
                if (is_array($file) && is_string($file['path'] ?? null)) {
                    $files[] = $file + ['pre_image' => ($file['before_sha256'] ?? null) === null ? null : PublicationJournal::PRE_IMAGE_DIR . '/' . $file['path']];
                }
            }
            $paths = array_map(static fn (array $file): string => (string) $file['path'], $files);
            $receipt['direct_edit'] = [
                'files' => $files,
                'git_ignored' => $git !== null ? $this->snapshotter->ignoredPaths($root, $paths) : [],
                'acknowledged_api_changes' => is_array($direct['acknowledged_api_changes'] ?? null) ? $direct['acknowledged_api_changes'] : [],
            ];
        }
        $receipt['recovery'] = [
            'source' => PublicationJournal::FILE,
            'attempt' => $journal['attempt'] ?? null,
            'state' => $verdict,
            'rolled_back' => $verdict === 'interrupted',
        ];

        return $receipt;
    }

    /**
     * A kept success receipt binds the stored Map manifest by digest. When the journal holds the manifest that receipt
     * names, it is written back if it was lost or damaged; when it does not, recovery stops and the journal stays.
     *
     * @param array<string, mixed> $journal
     * @param array<string, mixed>|null $receipt
     */
    private function restoreBoundManifest(string $bundlePath, array $journal, ?array $receipt): void
    {
        $reference = is_array($receipt['scope_evidence'] ?? null) ? $receipt['scope_evidence'] : null;
        if ($reference === null || ($reference['source'] ?? null) !== 'map_manifest') {
            return;
        }
        $observation = is_array($journal['observation'] ?? null) ? $journal['observation'] : [];
        $manifest = is_array($observation['map_manifest'] ?? null) ? $observation['map_manifest'] : null;
        /** @var array<string, string>|null $manifest */
        $encoded = $manifest === null ? null : MapManifestEvidence::encode($manifest);
        if ($encoded === null || MapManifestEvidence::reference($encoded) !== $reference) {
            throw new RuntimeException('The receipt names a Map manifest this publication journal does not hold; nothing was recorded. Inspect the bundle by hand.');
        }
        $path = $bundlePath . '/' . MapManifestEvidence::FILE;
        if (@file_get_contents($path) !== $encoded) {
            PublicationJournal::durableWrite($path, $encoded);
        }
    }

    /**
     * @param list<array{path: string, state: string}> $files
     * @param list<string> $removedTemporaryFiles
     * @return array<string, mixed>
     */
    private function recoveryResult(string $status, ?string $taskId, array $files, array $removedTemporaryFiles, ?string $conflict = null): array
    {
        return [
            'schema_version' => '1.0',
            'kind' => 'publication_recovery',
            'status' => $status,
            'task_id' => $taskId,
            'files' => $files,
            'removed_temporary_files' => $removedTemporaryFiles,
        ] + ($conflict === null ? [] : ['conflict' => $conflict]);
    }

    /**
     * Everything a receipt says about the run except its outcome and observations; also stored in the journal so a
     * recovered receipt has exactly the shape of a normal one.
     *
     * @param array<string, mixed> $plan
     * @return array<string, mixed>
     */
    private function receiptTemplate(ApplyRequest $request, array $plan, string $planRaw, PlanCapability $capability, ?string $mapDigest, ?string $mapIndexSha256): array
    {
        return [
            'schema_version' => '1.0',
            'task_id' => $request->label,
            'plan' => [
                'path' => $request->planPath,
                'sha256' => 'sha256:' . hash('sha256', $planRaw),
                'type' => $plan['type'] ?? null,
                'contract_version' => $plan['contract_version'] ?? null,
                'target_id' => $plan['target_id'] ?? null,
            ],
            'map_digest' => $mapDigest,
            'map_index_sha256' => $mapIndexSha256,
            'runner' => [
                'name' => $capability->runner,
                'exit_code' => null,
                'dry_run' => false,
                'model_input_tokens' => 0,
                'model_tool_calls' => 0,
            ],
        ];
    }

    /** @return array{files: list<array{path: string, before_sha256: string|null, after_sha256: string, edits: int}>, acknowledged_api_changes: list<string>} */
    private static function directJournalDetails(SourceEditPreparation $prepared): array
    {
        $files = [];
        foreach ($prepared->files as $file) {
            $files[] = [
                'path' => $file['path'],
                'before_sha256' => $file['before_sha256'],
                'after_sha256' => $file['after_sha256'],
                'edits' => $file['edits'],
            ];
        }

        return ['files' => $files, 'acknowledged_api_changes' => $prepared->acknowledgedApiChanges];
    }

    /**
     * Removes the journal once the receipt is persisted and the tree is in the state the receipt claims. Otherwise
     * (a rollback that did not complete, or a tree that matches neither side) it stays for `recover()`.
     */
    private function settleJournal(string $bundle, string $root, string $status): void
    {
        try {
            $journal = PublicationJournal::read($bundle);
            if ($journal === null) {
                return;
            }
            $entries = array_values(array_filter($journal['entries'], 'is_array'));
            $verdict = PublicationJournal::observe($root, $entries)['verdict'];
            if ($verdict === 'unchanged' || $verdict === ($status === 'runner_succeeded' ? 'published' : 'not_published')) {
                PublicationJournal::remove($bundle);
            }
        } catch (Throwable) {
            // A journal that cannot be settled now stays; `recover()` resolves it and every bundle consumer refuses it.
        }
    }

    /**
     * Observed changes minus the receipt bundle's own files: the journal and pre-images are written between the two
     * observations. Publication refuses a bundle that contains a published path, so nothing a plan changes is hidden.
     *
     * @param list<string> $changed
     * @return list<string>
     */
    private function withoutBundlePaths(array $changed, string $repositoryRoot, string $bundle): array
    {
        $prefix = PublicationJournal::bundlePrefix($repositoryRoot, $bundle);
        if ($prefix === null || $prefix === '') {
            return $changed;
        }

        return array_values(array_filter($changed, static fn (string $path): bool => !str_starts_with($path, $prefix)));
    }

    /**
     * A failed apply whose journal could not be settled (the rollback did not complete) needs `recover`, not a retry:
     * report it as `recovery_required`, keeping the original failure as the cause and in the message.
     */
    private function withPendingRecovery(Throwable $failure, string $bundle): Throwable
    {
        if (!PublicationJournal::exists($bundle)) {
            return $failure;
        }

        return new EditFailure(
            $failure->getMessage() . ' The bundle keeps its publication journal; run `agent-edit recover --bundle=' . $bundle . '` to restore a consistent state.',
            FailureCode::RecoveryRequired,
            ['bundle' => $bundle],
            $failure,
        );
    }

    /**
     * A successful apply whose journal is still in the bundle (it could not be removed, or the tree no longer matches
     * the receipt) leaves a bundle every consumer refuses, so it is not reported as plain success.
     */
    private function assertSettled(ApplyRequest $request, string $status): void
    {
        if ($status !== 'runner_succeeded' || !PublicationJournal::exists($request->outputDirectory)) {
            return;
        }

        throw new EditFailure(
            sprintf('The plan was published and its receipt written, but the publication journal could not be settled. Run `agent-edit recover --bundle=%s` before verifying.', $request->outputDirectory),
            FailureCode::RecoveryRequired,
            ['bundle' => $request->outputDirectory],
        );
    }

    private function existingBundle(string $repositoryRoot, string $bundle): string
    {
        $root = realpath($repositoryRoot);
        if (!is_string($root)) {
            throw new RuntimeException('Project root not found: ' . $repositoryRoot);
        }

        return $this->insideExisting($root, $bundle, 'bundle', true);
    }

    private function assertNoPendingPublication(string $bundle): void
    {
        if (PublicationJournal::exists($bundle)) {
            throw new EditFailure(sprintf(
                'Bundle %s has an unfinished publication (%s): its sources and evidence may disagree. Run `agent-edit recover --bundle=%s` first.',
                $bundle,
                PublicationJournal::FILE,
                $bundle,
            ), FailureCode::RecoveryRequired, ['bundle' => $bundle]);
        }
    }

    /**
     * Host policy (`ApplyRequest::$requireSourceHashes`): every edit of an existing file must name the digest of the
     * bytes the caller based it on. New files have no prior bytes and are exempt. Checked before anything is resolved,
     * written or authorized.
     */
    private static function assertSourceHashes(SourceEditPlanDocument $document): void
    {
        $missing = [];
        foreach ($document->edits as $position => $edit) {
            if ($edit->expectedSourceSha256 === null) {
                $missing[] = ['edit' => $position, 'path' => $edit->path];
            }
        }
        if ($missing !== []) {
            throw new EditFailure(sprintf(
                'This host requires expected_source_sha256 on every direct edit; missing on %s. Use the source_sha256 of the agent-map context slice you read, or the SHA-256 of the file as you read it.',
                implode(', ', array_map(static fn (array $entry): string => sprintf('edits[%d] (%s)', $entry['edit'], $entry['path']), $missing)),
            ), FailureCode::SourceHashRequired, ['missing' => $missing]);
        }
    }

    /**
     * A bundle that records an applied plan is evidence of a real mutation: a retry, a dry run or another plan never
     * overwrites it. Any other bundle may receive a new receipt, but a verification result next to it can only describe
     * an earlier attempt, so it is removed first.
     */
    private function claimBundle(string $bundle): void
    {
        $this->assertNoPendingPublication($bundle);
        $raw = @file_get_contents($bundle . '/' . self::RECEIPT_FILE);
        $receipt = is_string($raw) ? json_decode($raw, true) : null;
        if (is_array($receipt) && ($receipt['status'] ?? null) === 'runner_succeeded') {
            throw new RuntimeException(sprintf(
                'Bundle %s already records an applied plan and is never overwritten; use a new --task label or --output-dir.',
                $bundle,
            ));
        }
        $this->invalidateVerification($bundle);
    }

    /**
     * Holds an exclusive lock for one receipt bundle while `$operation` runs. The lock file lives in the system temp
     * directory (like the mutation lock), so the bundle itself stays free of lock artefacts.
     *
     * @template T
     * @param Closure(): T $operation
     * @return T
     */
    private function withBundleLock(string $bundle, Closure $operation): mixed
    {
        $real = realpath($bundle);
        if (!is_string($real)) {
            throw new RuntimeException('Unable to resolve receipt bundle: ' . $bundle);
        }
        $path = sys_get_temp_dir() . '/agent-edit-bundle-' . hash('sha256', $real) . '.lock';
        $handle = fopen($path, 'c');
        if (!is_resource($handle)) {
            throw new RuntimeException('Unable to open receipt bundle lock: ' . $path);
        }
        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            throw new RuntimeException('Unable to acquire receipt bundle lock: ' . $path);
        }

        try {
            return $operation();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** A verification result describes one applied state; once that state is gone it must not stay readable. */
    private function invalidateVerification(string $bundle): void
    {
        $verification = $bundle . '/' . self::VERIFICATION_FILE;
        if ((file_exists($verification) || is_link($verification)) && !@unlink($verification)) {
            throw new RuntimeException('Unable to invalidate the previous verification result in ' . $bundle . '.');
        }
    }

    private function canonicalRelative(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        if ($path === '' || str_starts_with($path, '/') || str_contains($path, "\0")) {
            throw new RuntimeException('Bundle contains a non-relative path: ' . $path);
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new RuntimeException('Bundle contains a non-canonical path: ' . $path);
            }
        }

        return $path;
    }

    /**
     * Verifies one applied receipt bundle against current source and a refreshed Map, and persists
     * `verification-result.json` into the bundle. All inputs must stay inside the repository root.
     *
     * @return array<string, mixed> the verification result document
     */
    public function verify(string $repositoryRoot, string $bundle, ?string $mapIndex = null, string $mapRoot = '.', ?string $acceptResidue = null): array
    {
        // Held from the pending-journal check through reading the receipt and persisting the verdict.
        return $this->withBundleLock(
            $this->existingBundle($repositoryRoot, $bundle),
            fn (): array => $this->verifyUnderBundleLock($repositoryRoot, $bundle, $mapIndex, $mapRoot, $acceptResidue),
        );
    }

    /** @return array<string, mixed> */
    private function verifyUnderBundleLock(string $repositoryRoot, string $bundle, ?string $mapIndex = null, string $mapRoot = '.', ?string $acceptResidue = null): array
    {
        $root = realpath($repositoryRoot);
        if (!is_string($root)) {
            throw new RuntimeException('Project root not found: ' . $repositoryRoot);
        }
        $bundlePath = $this->insideExisting($root, $bundle, 'bundle', true);
        $this->assertNoPendingPublication($bundlePath);
        // A failed attempt must never leave an earlier successful verdict available to bundle consumers.
        $verificationPath = $bundlePath . '/' . self::VERIFICATION_FILE;
        if ((file_exists($verificationPath) || is_link($verificationPath)) && !@unlink($verificationPath)) {
            throw new RuntimeException('Unable to invalidate previous refactor verification result.');
        }
        $receipt = $this->readReceipt($bundlePath . '/' . self::RECEIPT_FILE);
        $runner = $receipt['runner']['name'] ?? null;
        $capability = is_string($runner) ? $this->capabilities->findByRunner($runner) : null;
        if ($capability === null) {
            throw new RuntimeException('Refactor execution runner identity is not an executable agent-edit capability.');
        }

        $verifier = $this->verifierOverrides[$capability->verifier] ?? new ($capability->verifier)();
        if ($verifier instanceof MaplessBundleVerifier) {
            $result = $verifier->verify($bundlePath, $root);
            $this->writeAtomically($verificationPath, $this->json($result));

            return $result;
        }
        $mapIndexPath = $this->insideExisting($root, $mapIndex ?? MapArtifactPaths::forProject($root)->indexJson(), 'map index', false);
        $mapRootPath = $this->insideExisting($root, $mapRoot, 'map root', true);
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
            throw new InvalidInputFailure(
                sprintf(
                    'Unsupported plan type or contract version (got %s@%s); see `agent-edit capabilities`.',
                    is_string($type) ? $type : 'none',
                    is_string($version) ? $version : 'none',
                ),
                FailureCode::PlanUnsupported,
                ['plan_type' => is_string($type) ? $type : null, 'contract_version' => is_string($version) ? $version : null],
            );
        }

        return $capability;
    }

    /** @param array<string, mixed> $plan */
    private function applier(array $plan): PlanApplier|MaplessPlanApplier
    {
        $class = $this->capability($plan)->applier;

        return $this->applierOverrides[$class] ?? new $class();
    }

    /** @param array<string, mixed> $plan */
    private function mapApplier(array $plan): PlanApplier
    {
        $applier = $this->applier($plan);
        if (!$applier instanceof PlanApplier) {
            throw new RuntimeException('Plan capability is not a Map-backed applier.');
        }

        return $applier;
    }

    private function requireMap(?AgentMapIndex $map): AgentMapIndex
    {
        return $map ?? throw new InvalidArgumentException('This plan type needs an agent-map index.');
    }

    /** @return array<string, mixed> */
    private function decodePlan(string $path, string $raw): array
    {
        try {
            $decoded = str_ends_with(strtolower($path), '.toon')
                ? Toon::decode($raw)
                : json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            throw new EditFailure('Refactor plan is not valid ' . (str_ends_with(strtolower($path), '.toon') ? 'TOON' : 'JSON') . ' (' . $path . '): ' . $exception->getMessage(), FailureCode::PlanMalformed, ['path' => $path], $exception);
        }
        if (!is_array($decoded)) {
            throw new EditFailure('Refactor plan document must decode to an object: ' . $path, FailureCode::PlanMalformed, ['path' => $path]);
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
            throw new EditFailure('Refactor verify ' . $label . ' not found: ' . $path, FailureCode::InputNotFound, ['path' => $path]);
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
