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

/**
 * In-process failure points of the publication journal: evidence storage failing before or after publication, a
 * rollback that cannot complete, and the move / delete shapes Map plans produce. Real process kills are covered by
 * PublicationCrashRecoveryTest.
 */
final class PublicationRecoveryTest extends TestCase
{
    private const CALC = "<?php\n\nnamespace App;\n\nfinal class Calc\n{\n    public function add(int \$a, int \$b): int\n    {\n        return \$a - \$b;\n    }\n}\n";
    private const SHOW = "<?php\n\nnamespace App;\n\nfinal class Show\n{\n    public function name(): string\n    {\n        return 'old';\n    }\n}\n";

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

    /**
     * @param array{files: array<string, string>, final_paths: array<string, string>, source_hashes: array<string, string>} $prepared
     */
    private function journal(array $prepared, string $taskId = 'fix'): void
    {
        PublicationJournal::begin($this->bundle(), PublicationJournal::entriesFromPrepared($prepared, $this->project->root), [
            'receipt' => ['schema_version' => '1.0', 'task_id' => $taskId, 'runner' => ['name' => 'source-edit-plan', 'dry_run' => false]],
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
