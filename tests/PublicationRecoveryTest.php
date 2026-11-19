<?php

declare(strict_types=1);

namespace voku\AgentEdit\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentEdit\Apply\SourceEditPlanApplier;
use voku\AgentEdit\EditEngine;
use voku\AgentEdit\Failure\EditFailure;
use voku\AgentEdit\Failure\FailureCode;
use voku\AgentEdit\Receipt\ApplyRequest;
use voku\AgentEdit\Receipt\PublicationJournal;
use voku\AgentEdit\Receipt\ReceiptNotPersistedException;
use voku\AgentEdit\Tests\Support\TemporaryProject;
use voku\AgentEdit\Verify\MapManifestEvidence;

/**
 * In-process failure points of the publication journal: evidence storage failing before or after publication, a
 * rollback that cannot complete, and the move / delete shapes Map plans produce. Real process kills are covered by
 * PublicationCrashRecoveryTest.
 */
final class PublicationRecoveryTest extends TestCase
{
    private const CALC = "<?php\n\nnamespace App;\n\nfinal class Calc\n{\n    public function add(int \$a, int \$b): int\n    {\n        return \$a - \$b;\n    }\n}\n";
    private const SHOW = "<?php\n\nnamespace App;\n\nfinal class Show\n{\n    public function name(): string\n    {\n        return 'old';\n    }\n}\n";
    private const CALC_PUBLISHED = "<?php\n\nnamespace App;\n\nfinal class Calc\n{\n    public function add(int \$a, int \$b): int\n    {\n        return \$a + \$b;\n    }\n}\n";
    private const SHOW_PUBLISHED = "<?php\n\nnamespace App;\n\nfinal class Show\n{\n    public function name(): string\n    {\n        return 'new';\n    }\n}\n";

    private TemporaryProject $project;

    protected function setUp(): void
    {
        $this->project = new TemporaryProject(['src/Calc.php' => self::CALC, 'src/Show.php' => self::SHOW, '.gitignore' => ".agent-edit/\nplan.json\n"]);
        $root = escapeshellarg($this->project->root);
        exec("git -C {$root} init -q && git -C {$root} add -A && git -C {$root} -c user.email=t@t -c user.name=t commit -qm init", $output, $exit);
        self::assertSame(0, $exit, implode("\n", $output));
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function testANormalApplyLeavesNoJournalAndTheReceiptShapeIsUnchanged(): void
    {
        $this->writePlan();
        $receipt = (new EditEngine())->applyWithReceipt($this->request());

        self::assertTrue($receipt->succeeded());
        self::assertFileDoesNotExist($this->bundle() . '/' . PublicationJournal::FILE);
        $document = $this->receipt();
        self::assertSame(
            ['schema_version', 'status', 'task_id', 'plan', 'map_digest', 'map_index_sha256', 'runner', 'changed_files', 'changed_files_source', 'direct_edit'],
            array_keys($document),
            'existing receipt consumers see exactly the keys they saw before; recovery fields appear only on recovered receipts',
        );
        self::assertSame(['src/Calc.php', 'src/Show.php'], $document['changed_files']);
        self::assertSame(self::CALC, (string) file_get_contents($this->bundle() . '/before/src/Calc.php'));
    }

    public function testEvidenceStorageFailingBeforePublicationChangesNoSource(): void
    {
        $this->writePlan();
        mkdir($this->bundle(), 0o775, true);
        file_put_contents($this->bundle() . '/' . PublicationJournal::PRE_IMAGE_DIR, 'a file where the pre-image directory must go');

        try {
            (new EditEngine())->applyWithReceipt($this->request());
            self::fail('A journal that cannot be written must stop the publication.');
        } catch (RuntimeException $exception) {
            self::assertNotInstanceOf(ReceiptNotPersistedException::class, $exception);
            self::assertStringContainsString('evidence', $exception->getMessage());
        }

        self::assertSame(self::CALC, $this->project->read('src/Calc.php'));
        self::assertSame(self::SHOW, $this->project->read('src/Show.php'));
        self::assertFileDoesNotExist($this->bundle() . '/' . PublicationJournal::FILE);
        self::assertSame('runner_failed', $this->receipt()['status']);
    }

    public function testReceiptStorageFailingAfterPublicationIsRecoverableIntoAVerifiedReceipt(): void
    {
        $this->writePlan();
        // A non-empty directory where the receipt file must go: the receipt write fails after publication.
        mkdir($this->bundle() . '/' . EditEngine::RECEIPT_FILE . '/keep', 0o775, true);

        try {
            (new EditEngine())->applyWithReceipt($this->request());
            self::fail('Expected the receipt write to fail.');
        } catch (ReceiptNotPersistedException $exception) {
            self::assertSame(['src/Calc.php', 'src/Show.php'], $exception->changedFiles);
            self::assertStringContainsString('agent-edit recover --bundle=', $exception->getMessage());
            self::assertSame(FailureCode::RecoveryRequired, $exception->failureCode());
            self::assertSame(['src/Calc.php', 'src/Show.php'], $exception->details()['changed_files']);
        }
        self::assertStringContainsString('return $a + $b;', $this->project->read('src/Calc.php'));
        self::assertFileExists($this->bundle() . '/' . PublicationJournal::FILE);

        rmdir($this->bundle() . '/' . EditEngine::RECEIPT_FILE . '/keep');
        rmdir($this->bundle() . '/' . EditEngine::RECEIPT_FILE);
        $result = (new EditEngine())->recover($this->project->root, '.agent-edit/receipts/fix');

        self::assertSame('published', $result['status']);
        $receipt = $this->receipt();
        self::assertSame('runner_succeeded', $receipt['status']);
        self::assertSame(0, $receipt['runner']['exit_code']);
        self::assertSame(['src/Calc.php', 'src/Show.php'], $receipt['changed_files']);
        self::assertSame(['src/Calc.php', 'src/Show.php'], array_column($receipt['direct_edit']['files'], 'path'));
        self::assertSame([1, 1], array_column($receipt['direct_edit']['files'], 'edits'));
        self::assertSame('passed', (new EditEngine())->verify($this->project->root, '.agent-edit/receipts/fix')['status']);
        (new EditEngine())->undo($this->project->root, '.agent-edit/receipts/fix');
        self::assertSame(self::CALC, $this->project->read('src/Calc.php'));
    }

    public function testACleanlyRolledBackApplySettlesItsJournal(): void
    {
        $this->writePlan();
        $engine = new EditEngine(applierOverrides: [
            SourceEditPlanApplier::class => new SourceEditPlanApplier(static fn (string $from, string $to): bool => false),
        ]);

        try {
            $engine->applyWithReceipt($this->request());
            self::fail('Every publication rename fails.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('every file was restored', $exception->getMessage());
        }

        self::assertSame(self::CALC, $this->project->read('src/Calc.php'));
        self::assertSame('runner_failed', $this->receipt()['status']);
        self::assertFileDoesNotExist($this->bundle() . '/' . PublicationJournal::FILE, 'all sources are original: nothing to recover');
    }

    public function testAnIncompleteRollbackKeepsTheJournalAndRecoveryFinishesIt(): void
    {
        $this->writePlan();
        // The first file is published, every later rename (the second file and the rollback itself) fails.
        $renames = 0;
        $engine = new EditEngine(applierOverrides: [
            SourceEditPlanApplier::class => new SourceEditPlanApplier(static function (string $from, string $to) use (&$renames): bool {
                return ++$renames === 1 && rename($from, $to);
            }),
        ]);

        try {
            $engine->applyWithReceipt($this->request());
            self::fail('The publication must fail.');
        } catch (EditFailure $exception) {
            self::assertSame(FailureCode::RecoveryRequired, $exception->failureCode(), 'not a retryable failure: the journal must be recovered');
            self::assertStringContainsString('rollback was incomplete', $exception->getMessage());
            self::assertStringContainsString('agent-edit recover --bundle=', $exception->getMessage());
        }
        self::assertStringContainsString('return $a + $b;', $this->project->read('src/Calc.php'), 'Calc stayed published');
        self::assertFileExists($this->bundle() . '/' . PublicationJournal::FILE, 'a mixed tree keeps the journal');

        $result = (new EditEngine())->recover($this->project->root, '.agent-edit/receipts/fix');

        self::assertSame('rolled_back', $result['status']);
        self::assertSame(self::CALC, $this->project->read('src/Calc.php'));
        self::assertSame(self::SHOW, $this->project->read('src/Show.php'));
        self::assertSame([], array_values(array_filter($this->project->files(), static fn (string $path): bool => str_contains($path, '.agent-edit-tx-'))));
    }

    public function testInterruptedMovesAndDeletionsAreRolledBackFromPreImages(): void
    {
        // The shape a Map plan journals: Show.php moves to src/View/Show.php with new content, Calc.php is deleted.
        $moved = str_replace('old', 'new', self::SHOW);
        $this->journal([
            'files' => [$this->project->path('src/Show.php') => $moved],
            'final_paths' => [$this->project->path('src/Show.php') => $this->project->path('src/View/Show.php')],
            'source_hashes' => [
                $this->project->path('src/Show.php') => 'sha256:' . hash('sha256', self::SHOW),
                $this->project->path('src/Calc.php') => 'sha256:' . hash('sha256', self::CALC),
            ],
        ]);
        // Killed after the destination was written and the deletion happened, before the source was removed.
        $this->project->write('src/View/Show.php', $moved);
        unlink($this->project->path('src/Calc.php'));

        $result = (new EditEngine())->recover($this->project->root, '.agent-edit/receipts/fix');

        self::assertSame('rolled_back', $result['status']);
        self::assertSame([
            ['path' => 'src/Calc.php', 'state' => PublicationJournal::STATE_AFTER],
            ['path' => 'src/Show.php', 'state' => PublicationJournal::STATE_BEFORE],
            ['path' => 'src/View/Show.php', 'state' => PublicationJournal::STATE_AFTER],
        ], $result['files']);
        self::assertSame(self::CALC, $this->project->read('src/Calc.php'));
        self::assertSame(self::SHOW, $this->project->read('src/Show.php'));
        self::assertFileDoesNotExist($this->project->path('src/View/Show.php'));
    }

    public function testACompletedMoveAndDeletionIsRecordedAsPublished(): void
    {
        $moved = str_replace('old', 'new', self::SHOW);
        $this->journal([
            'files' => [$this->project->path('src/Show.php') => $moved],
            'final_paths' => [$this->project->path('src/Show.php') => $this->project->path('src/View/Show.php')],
            'source_hashes' => [
                $this->project->path('src/Show.php') => 'sha256:' . hash('sha256', self::SHOW),
                $this->project->path('src/Calc.php') => 'sha256:' . hash('sha256', self::CALC),
            ],
        ]);
        $this->project->write('src/View/Show.php', $moved);
        unlink($this->project->path('src/Show.php'));
        unlink($this->project->path('src/Calc.php'));

        $result = (new EditEngine())->recover($this->project->root, '.agent-edit/receipts/fix');

        self::assertSame('published', $result['status']);
        self::assertSame($moved, $this->project->read('src/View/Show.php'));
        self::assertSame('runner_succeeded', $this->receipt()['status']);
        // This hand-made journal recorded no observation, so the receipt does not invent changed files.
        self::assertSame('unavailable', $this->receipt()['changed_files_source']);
    }

    public function testRecoveryAuthorizationIsAskedBeforeAnythingChangesAndCanRefuse(): void
    {
        $this->journal([
            'files' => [$this->project->path('src/Calc.php') => 'changed'],
            'final_paths' => [$this->project->path('src/Calc.php') => $this->project->path('src/Calc.php')],
            'source_hashes' => [$this->project->path('src/Calc.php') => 'sha256:' . hash('sha256', self::CALC)],
        ], 'auth-task');
        $this->project->write('src/Calc.php', 'changed');

        try {
            (new EditEngine())->recover($this->project->root, '.agent-edit/receipts/fix', static function (string $task): void {
                throw new RuntimeException('host refused ' . $task);
            });
            self::fail('The host refusal must stop recovery.');
        } catch (RuntimeException $exception) {
            self::assertSame('host refused auth-task', $exception->getMessage());
        }
        self::assertFileExists($this->bundle() . '/' . PublicationJournal::FILE);
        self::assertFileDoesNotExist($this->bundle() . '/' . EditEngine::RECEIPT_FILE);
    }

    public function testASuccessReceiptOverOriginalBytesIsAReceiptConflict(): void
    {
        $this->journal($this->editBoth());
        file_put_contents($this->bundle() . '/' . EditEngine::RECEIPT_FILE, json_encode(['status' => 'runner_succeeded'], JSON_THROW_ON_ERROR));

        $result = (new EditEngine())->recover($this->project->root, '.agent-edit/receipts/fix');

        self::assertSame('conflict', $result['status']);
        self::assertSame('receipt_claims_publication', $result['conflict']);
        self::assertSame(self::CALC, $this->project->read('src/Calc.php'));
        self::assertFileExists($this->bundle() . '/' . PublicationJournal::FILE);
    }

    public function testACompletedRollbackSurvivesALostRecoveryResult(): void
    {
        $this->journal($this->editBoth());
        $this->project->write('src/Calc.php', self::CALC_PUBLISHED);
        mkdir($this->bundle() . '/' . EditEngine::RECOVERY_RESULT_FILE . '/keep', 0o775, true);

        try {
            (new EditEngine())->recover($this->project->root, '.agent-edit/receipts/fix');
            self::fail('The recovery result cannot be written.');
        } catch (RuntimeException) {
        }
        self::assertSame(self::CALC, $this->project->read('src/Calc.php'), 'the rollback itself completed');
        self::assertFileExists($this->bundle() . '/' . PublicationJournal::FILE);

        rmdir($this->bundle() . '/' . EditEngine::RECOVERY_RESULT_FILE . '/keep');
        rmdir($this->bundle() . '/' . EditEngine::RECOVERY_RESULT_FILE);
        $result = (new EditEngine())->recover($this->project->root, '.agent-edit/receipts/fix');

        self::assertSame('rolled_back', $result['status'], 'a retry must not turn the completed rollback into "never published"');
        self::assertTrue($this->receipt()['recovery']['rolled_back']);
    }

    public function testARollbackReceiptOfAnEarlierAttemptOnAReusedBundleIsNotThisAttempts(): void
    {
        $this->journal($this->editBoth());
        $this->project->write('src/Calc.php', self::CALC_PUBLISHED);
        self::assertSame('rolled_back', (new EditEngine())->recover($this->project->root, '.agent-edit/receipts/fix')['status']);
        self::assertTrue($this->receipt()['recovery']['rolled_back']);

        // The bundle is reused (a failed receipt does not block that); the new attempt dies before publishing.
        $this->journal($this->editBoth());
        $result = (new EditEngine())->recover($this->project->root, '.agent-edit/receipts/fix');

        self::assertSame('not_published', $result['status'], 'the earlier attempt\'s rollback must not be reported for this one');
        self::assertFalse($this->receipt()['recovery']['rolled_back']);
    }

    public function testAJournalOriginalWithoutPermissionBitsIsRefusedBeforeAnythingChanges(): void
    {
        chmod($this->project->path('src/Calc.php'), 0o600);
        $this->journal([
            'files' => [],
            'final_paths' => [],
            'source_hashes' => [
                $this->project->path('src/Calc.php') => 'sha256:' . hash('sha256', self::CALC),
                $this->project->path('src/Show.php') => 'sha256:' . hash('sha256', self::SHOW),
            ],
        ]);
        unlink($this->project->path('src/Calc.php')); // interrupted: Calc's deletion was published, Show's was not
        $path = $this->bundle() . '/' . PublicationJournal::FILE;
        $journal = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        unset($journal['entries'][0]['mode']);
        file_put_contents($path, json_encode($journal, JSON_THROW_ON_ERROR));

        $message = null;
        try {
            (new EditEngine())->recover($this->project->root, '.agent-edit/receipts/fix');
        } catch (RuntimeException $exception) {
            $message = $exception->getMessage();
        }
        self::assertNotNull($message, 'recovery must refuse');
        self::assertStringContainsString('permission bits', $message);
        self::assertFileDoesNotExist($this->project->path('src/Calc.php'));
        self::assertFileExists($path);
    }

    public function testAKeptSuccessReceiptGetsTheManifestItNamesBack(): void
    {
        $manifest = ['src/Calc.php' => 'sha256:' . hash('sha256', self::CALC), 'src/Show.php' => 'sha256:' . hash('sha256', self::SHOW)];
        $encoded = MapManifestEvidence::encode($manifest);
        $this->journalWithManifest($manifest);
        $this->project->write('src/Calc.php', self::CALC_PUBLISHED);
        $this->project->write('src/Show.php', self::SHOW_PUBLISHED);
        file_put_contents($this->bundle() . '/' . EditEngine::RECEIPT_FILE, json_encode([
            'schema_version' => '1.0', 'task_id' => 'fix', 'status' => 'runner_succeeded',
            'scope_evidence' => MapManifestEvidence::reference($encoded),
        ], JSON_THROW_ON_ERROR));
        file_put_contents($this->bundle() . '/' . MapManifestEvidence::FILE, 'damaged');

        self::assertSame('published', (new EditEngine())->recover($this->project->root, '.agent-edit/receipts/fix')['status']);
        self::assertSame($encoded, file_get_contents($this->bundle() . '/' . MapManifestEvidence::FILE));
        self::assertFileDoesNotExist($this->bundle() . '/' . PublicationJournal::FILE);
    }

    public function testAKeptSuccessReceiptNamingAnotherManifestKeepsTheJournal(): void
    {
        $manifest = ['src/Calc.php' => 'sha256:' . hash('sha256', self::CALC), 'src/Show.php' => 'sha256:' . hash('sha256', self::SHOW)];
        $this->journalWithManifest($manifest);
        $this->project->write('src/Calc.php', self::CALC_PUBLISHED);
        $this->project->write('src/Show.php', self::SHOW_PUBLISHED);
        file_put_contents($this->bundle() . '/' . EditEngine::RECEIPT_FILE, json_encode([
            'schema_version' => '1.0', 'task_id' => 'fix', 'status' => 'runner_succeeded',
            'scope_evidence' => MapManifestEvidence::reference('another manifest'),
        ], JSON_THROW_ON_ERROR));

        $message = null;
        try {
            (new EditEngine())->recover($this->project->root, '.agent-edit/receipts/fix');
        } catch (RuntimeException $exception) {
            $message = $exception->getMessage();
        }
        self::assertNotNull($message, 'recovery must refuse');
        self::assertStringContainsString('Map manifest', $message);
        self::assertFileExists($this->bundle() . '/' . PublicationJournal::FILE);
    }

    public function testRestoredFilesKeepTheirModeAndPreImagesArePrivate(): void
    {
        chmod($this->project->path('src/Calc.php'), 0o600);
        $this->journal([
            'files' => [],
            'final_paths' => [],
            'source_hashes' => [
                $this->project->path('src/Calc.php') => 'sha256:' . hash('sha256', self::CALC),
                $this->project->path('src/Show.php') => 'sha256:' . hash('sha256', self::SHOW),
            ],
        ]);
        self::assertSame(0o600, fileperms($this->bundle() . '/before/src/Calc.php') & 0o777);
        self::assertSame(0o600, fileperms($this->bundle() . '/before/src/Show.php') & 0o777, 'pre-images are private whatever the source mode');
        unlink($this->project->path('src/Calc.php')); // the deletion of Calc was published, Show's was not

        self::assertSame('rolled_back', (new EditEngine())->recover($this->project->root, '.agent-edit/receipts/fix')['status']);
        self::assertSame(self::CALC, $this->project->read('src/Calc.php'));
        self::assertSame(0o600, fileperms($this->project->path('src/Calc.php')) & 0o777, 'restored with its original mode, not 0644');
    }

    public function testARestoreOverAnExistingFileUsesTheRecordedModeNotTheCurrentOne(): void
    {
        chmod($this->project->path('src/Calc.php'), 0o600);
        $this->journal($this->editBoth());
        $this->project->write('src/Calc.php', self::CALC_PUBLISHED); // published (Show was not): a mix
        chmod($this->project->path('src/Calc.php'), 0o644); // widened after publication

        self::assertSame('rolled_back', (new EditEngine())->recover($this->project->root, '.agent-edit/receipts/fix')['status']);
        self::assertSame(self::CALC, $this->project->read('src/Calc.php'));
        self::assertSame(0o600, fileperms($this->project->path('src/Calc.php')) & 0o777, 'the private original must not come back 0644');
    }

    public function testPrivateEvidenceWritesRestoreTheProcessUmask(): void
    {
        $previous = umask(0o022);
        try {
            PublicationJournal::durableWrite($this->bundle() . '/private.txt', 'secret', 0o600);
            self::assertSame(0o022, umask(), 'the private creation mode must not leak into the rest of the process');
            self::assertSame(0o600, fileperms($this->bundle() . '/private.txt') & 0o777);
            PublicationJournal::durableWrite($this->bundle() . '/plain.txt', 'public');
            self::assertSame(0o644, fileperms($this->bundle() . '/plain.txt') & 0o777, 'writes without a mode keep the umask default');
        } finally {
            umask($previous);
        }
    }

    public function testANonUtf8UntrackedFilenameNeitherBreaksJournalingNorEntersTheEvidence(): void
    {
        file_put_contents($this->project->path("notes-\xff.txt"), 'scratch');
        $this->writePlan();
        mkdir($this->bundle() . '/' . EditEngine::RECEIPT_FILE . '/keep', 0o775, true);

        try {
            (new EditEngine())->applyWithReceipt($this->request());
            self::fail('Expected the receipt write to fail.');
        } catch (ReceiptNotPersistedException) {
        }
        rmdir($this->bundle() . '/' . EditEngine::RECEIPT_FILE . '/keep');
        rmdir($this->bundle() . '/' . EditEngine::RECEIPT_FILE);

        self::assertSame('published', (new EditEngine())->recover($this->project->root, '.agent-edit/receipts/fix')['status']);
        self::assertSame(['src/Calc.php', 'src/Show.php'], $this->receipt()['changed_files']);
        self::assertSame('passed', (new EditEngine())->verify($this->project->root, '.agent-edit/receipts/fix')['status']);
    }

    public function testAMalformedJournalIsRefusedBeforeAnythingChanges(): void
    {
        $this->journal($this->editBoth());
        $this->project->write('src/Calc.php', self::CALC_PUBLISHED);
        $path = $this->bundle() . '/' . PublicationJournal::FILE;
        $journal = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        unset($journal['entries'][0]['before_sha256']);
        file_put_contents($path, json_encode($journal, JSON_THROW_ON_ERROR));

        try {
            (new EditEngine())->recover($this->project->root, '.agent-edit/receipts/fix');
            self::fail('A journal entry without its original digest must not be interpreted.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('incomplete', $exception->getMessage());
        }
        self::assertSame(self::CALC_PUBLISHED, $this->project->read('src/Calc.php'));
    }

    public function testBytesAPlanDoesNotChangeAreNeutral(): void
    {
        // Calc is "edited" to identical bytes, Show's real edit never happened: publication never started.
        $this->journal([
            'files' => [$this->project->path('src/Calc.php') => self::CALC, $this->project->path('src/Show.php') => self::SHOW_PUBLISHED],
            'final_paths' => [],
            'source_hashes' => [
                $this->project->path('src/Calc.php') => 'sha256:' . hash('sha256', self::CALC),
                $this->project->path('src/Show.php') => 'sha256:' . hash('sha256', self::SHOW),
            ],
        ]);
        touch($this->project->path('src/Calc.php'), 1_000_000);

        $result = (new EditEngine())->recover($this->project->root, '.agent-edit/receipts/fix');

        self::assertSame('not_published', $result['status']);
        self::assertContains(['path' => 'src/Calc.php', 'state' => PublicationJournal::STATE_UNCHANGED], $result['files']);
        self::assertSame(1_000_000, filemtime($this->project->path('src/Calc.php')), 'an unchanged file is never rewritten');
    }

    public function testOnlyTemporaryFilesWithThisPublicationsBytesAreRemoved(): void
    {
        // Present before the publication started, with exactly the bytes it would publish: still not this attempt's.
        $preexisting = 'src/Calc.php.agent-edit-tx-stage-aaaaaaaaaaaaaaaa';
        $this->project->write($preexisting, self::CALC_PUBLISHED);
        $this->journal($this->editBoth());
        $owned = 'src/Calc.php.agent-edit-tx-stage-0123456789abcdef';
        $foreign = 'src/Calc.php.agent-edit-tx-backup-fedcba9876543210';
        $this->project->write($owned, self::CALC_PUBLISHED);
        $this->project->write($foreign, 'somebody else\'s file that happens to match the naming pattern');

        $result = (new EditEngine())->recover($this->project->root, '.agent-edit/receipts/fix');

        self::assertSame('not_published', $result['status']);
        self::assertSame([$owned], $result['removed_temporary_files']);
        self::assertFileDoesNotExist($this->project->path($owned));
        self::assertFileExists($this->project->path($foreign));
        self::assertSame(self::CALC_PUBLISHED, $this->project->read($preexisting));
    }

    public function testAPreexistingBackupNamedFileIsNoEvidenceOfADisplacedSource(): void
    {
        $this->project->write('src/Calc.php.agent-edit-tx-backup-aaaaaaaaaaaaaaaa', self::CALC);
        $this->journal($this->editBoth());
        unlink($this->project->path('src/Calc.php')); // removed by someone else, not moved aside by this publisher

        $result = (new EditEngine())->recover($this->project->root, '.agent-edit/receipts/fix');

        self::assertSame('conflict', $result['status']);
        self::assertSame('unexpected', $result['files'][0]['state']);
        self::assertFileExists($this->project->path('src/Calc.php.agent-edit-tx-backup-aaaaaaaaaaaaaaaa'));
    }

    public function testAParentDirectoryLinkedOutsideTheProjectIsAConflict(): void
    {
        $this->journal($this->editBoth());
        $outside = sys_get_temp_dir() . '/agent-edit-outside-' . bin2hex(random_bytes(6));
        rename($this->project->path('src'), $outside);
        symlink($outside, $this->project->path('src'));
        file_put_contents($outside . '/Calc.php.agent-edit-tx-stage-0123456789abcdef', self::CALC);

        try {
            $result = (new EditEngine())->recover($this->project->root, '.agent-edit/receipts/fix');
            self::assertSame('conflict', $result['status']);
            self::assertSame('unexpected_file', $result['conflict']);
            self::assertFileExists($outside . '/Calc.php.agent-edit-tx-stage-0123456789abcdef', 'nothing outside the project is cleaned up');
        } finally {
            unlink($this->project->path('src'));
            exec('rm -rf ' . escapeshellarg($outside));
        }
    }

    public function testABundleInsideAPublishedDirectoryIsRefusedBeforeWriting(): void
    {
        $this->writePlan();

        try {
            (new EditEngine())->applyWithReceipt(new ApplyRequest(
                repositoryRoot: $this->project->root,
                planPath: $this->project->path('plan.json'),
                mapIndexPath: null,
                mapRoot: $this->project->root,
                outputDirectory: $this->project->path('src'),
                label: 'fix',
            ));
            self::fail('A bundle that contains published paths would hide them from the evidence.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('output directory outside the edited files', $exception->getMessage());
        }
        self::assertSame(self::CALC, $this->project->read('src/Calc.php'));
        self::assertFileDoesNotExist($this->project->path('src/' . PublicationJournal::FILE));
    }

    public function testASuccessfulApplyThatCannotSettleItsJournalReportsRecoveryRequired(): void
    {
        $this->writePlan();
        $real = new SourceEditPlanApplier();
        $tampering = new class ($real, $this->project->path('src/Calc.php')) implements \voku\AgentEdit\Apply\MaplessPlanApplier {
            public function __construct(private SourceEditPlanApplier $inner, private string $file)
            {
            }

            public function preflight(array $plan, string $root): array
            {
                return $this->inner->preflight($plan, $root);
            }

            public function apply(array $plan, string $root): \voku\AgentEdit\Apply\EditResult
            {
                $result = $this->inner->apply($plan, $root);
                file_put_contents($this->file, "<?php\n// changed by someone else right after publication\n");

                return $result;
            }
        };

        try {
            (new EditEngine(applierOverrides: [SourceEditPlanApplier::class => $tampering]))->applyWithReceipt($this->request());
            self::fail('A success whose journal stays must not look like plain success.');
        } catch (EditFailure $exception) {
            self::assertSame(FailureCode::RecoveryRequired, $exception->failureCode());
        }
        self::assertSame('runner_succeeded', $this->receipt()['status']);
        self::assertFileExists($this->bundle() . '/' . PublicationJournal::FILE);
    }

    public function testRollbackAndReceiptFailingTogetherStillPointToRecovery(): void
    {
        $this->writePlan();
        mkdir($this->bundle() . '/' . EditEngine::RECEIPT_FILE . '/keep', 0o775, true);
        $renames = 0;
        $engine = new EditEngine(applierOverrides: [
            SourceEditPlanApplier::class => new SourceEditPlanApplier(static function (string $from, string $to) use (&$renames): bool {
                return ++$renames === 1 && rename($from, $to);
            }),
        ]);

        try {
            $engine->applyWithReceipt($this->request());
            self::fail('The publication must fail.');
        } catch (EditFailure $exception) {
            self::assertSame(FailureCode::RecoveryRequired, $exception->failureCode());
            self::assertStringContainsString('rollback was incomplete', $exception->getMessage());
        }
    }

    public function testABundleOutsideTheProjectIsRecoverableByItsAbsolutePath(): void
    {
        $this->writePlan();
        $outside = sys_get_temp_dir() . '/agent-edit-outside-bundle-' . bin2hex(random_bytes(6));
        mkdir($outside . '/' . EditEngine::RECEIPT_FILE . '/keep', 0o775, true);
        try {
            try {
                (new EditEngine())->applyWithReceipt(new ApplyRequest(
                    repositoryRoot: $this->project->root,
                    planPath: $this->project->path('plan.json'),
                    mapIndexPath: null,
                    mapRoot: $this->project->root,
                    outputDirectory: $outside,
                    label: 'fix',
                ));
                self::fail('Expected the receipt write to fail.');
            } catch (ReceiptNotPersistedException $exception) {
                self::assertStringContainsString($outside, $exception->getMessage());
            }
            rmdir($outside . '/' . EditEngine::RECEIPT_FILE . '/keep');
            rmdir($outside . '/' . EditEngine::RECEIPT_FILE);

            self::assertSame('published', (new EditEngine())->recover($this->project->root, $outside)['status']);
            self::assertFileDoesNotExist($outside . '/' . PublicationJournal::FILE);
        } finally {
            exec('rm -rf ' . escapeshellarg($outside));
        }
    }

    /** @return array{files: array<string, string>, final_paths: array<string, string>, source_hashes: array<string, string>} */
    private function editBoth(): array
    {
        return [
            'files' => [$this->project->path('src/Calc.php') => self::CALC_PUBLISHED, $this->project->path('src/Show.php') => self::SHOW_PUBLISHED],
            'final_paths' => [],
            'source_hashes' => [
                $this->project->path('src/Calc.php') => 'sha256:' . hash('sha256', self::CALC),
                $this->project->path('src/Show.php') => 'sha256:' . hash('sha256', self::SHOW),
            ],
        ];
    }

    /**
     * @param array{files: array<string, string>, final_paths: array<string, string>, source_hashes: array<string, string>} $prepared
     */
    private function journal(array $prepared, string $taskId = 'fix'): void
    {
        PublicationJournal::begin($this->bundle(), PublicationJournal::entriesFromPrepared($prepared, $this->project->root), [
            'receipt' => ['schema_version' => '1.0', 'task_id' => $taskId, 'runner' => ['name' => 'source-edit-plan', 'dry_run' => false]],
        ]);
    }

    /** @param array<string, string> $manifest */
    private function journalWithManifest(array $manifest): void
    {
        PublicationJournal::begin($this->bundle(), PublicationJournal::entriesFromPrepared($this->editBoth(), $this->project->root), [
            'receipt' => ['schema_version' => '1.0', 'task_id' => 'fix', 'runner' => ['name' => 'map-edit-plan', 'dry_run' => false]],
            'observation' => ['git' => null, 'map_manifest' => $manifest, 'map_root' => $this->project->root],
        ]);
    }

    private function writePlan(): void
    {
        file_put_contents($this->project->path('plan.json'), json_encode([
            'type' => 'source_edit_plan',
            'contract_version' => '1.0',
            'target_id' => 'fix',
            'edits' => [
                ['path' => 'src/Calc.php', 'find' => 'return $a - $b;', 'replace' => 'return $a + $b;'],
                ['path' => 'src/Show.php', 'find' => "return 'old';", 'replace' => "return 'new';"],
            ],
        ], JSON_THROW_ON_ERROR));
    }

    private function request(): ApplyRequest
    {
        return new ApplyRequest(
            repositoryRoot: $this->project->root,
            planPath: $this->project->path('plan.json'),
            mapIndexPath: null,
            mapRoot: $this->project->root,
            outputDirectory: $this->bundle(),
            label: 'fix',
        );
    }

    private function bundle(): string
    {
        return $this->project->path('.agent-edit/receipts/fix');
    }

    /** @return array<string, mixed> */
    private function receipt(): array
    {
        return json_decode((string) file_get_contents($this->bundle() . '/' . EditEngine::RECEIPT_FILE), true, 512, JSON_THROW_ON_ERROR);
    }
}
