<?php

declare(strict_types=1);

namespace voku\AgentEdit\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentEdit\EditEngine;
use voku\AgentEdit\Receipt\ApplyRequest;
use voku\AgentEdit\Receipt\ReceiptNotPersistedException;

final class DirectEditEngineTest extends TestCase
{
    private const SOURCE = "<?php\n\ndeclare(strict_types=1);\n\nnamespace Demo;\n\nfinal class Calc\n{\n    public function add(int \$a, int \$b): int\n    {\n        return \$a - \$b;\n    }\n}\n";

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-edit-direct-engine-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0o775, true);
        file_put_contents($this->root . '/src/Calc.php', self::SOURCE);
    }

    protected function tearDown(): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->root);
    }

    public function testAppliesWithoutAMapAndBindsReceiptPreImagesAndDigests(): void
    {
        $this->initGit();
        $receipt = $this->apply($this->fixPlan(), 'fix');

        self::assertSame('runner_succeeded', $receipt->status);
        self::assertSame('source_edit_plan', $receipt->planType);
        self::assertStringContainsString('return $a + $b;', $this->read('src/Calc.php'));

        $execution = $this->json('.agent-edit/receipts/fix/execution.json');
        self::assertNull($execution['map_digest']);
        self::assertNull($execution['map_index_sha256']);
        self::assertSame('source-edit-plan', $execution['runner']['name']);
        self::assertSame(0, $execution['runner']['model_tool_calls']);
        self::assertSame(['src/Calc.php'], $execution['changed_files']);
        self::assertSame('git_status_diff', $execution['changed_files_source']);
        $file = $execution['direct_edit']['files'][0];
        self::assertSame('src/Calc.php', $file['path']);
        self::assertSame(hash('sha256', self::SOURCE), $file['before_sha256']);
        self::assertSame(hash('sha256', $this->read('src/Calc.php')), $file['after_sha256']);
        self::assertSame('before/src/Calc.php', $file['pre_image']);
        self::assertSame(self::SOURCE, $this->read('.agent-edit/receipts/fix/before/src/Calc.php'));
    }

    public function testVerifyPassesWithoutAMapAndWritesTheResult(): void
    {
        $this->initGit();
        $this->apply($this->fixPlan(), 'fix');

        $result = (new EditEngine())->verify($this->root, '.agent-edit/receipts/fix');

        self::assertSame('passed', $result['status']);
        self::assertSame('source_edit_plan_verification', $result['kind']);
        self::assertSame(['src/Calc.php'], $result['changed_files']);
        self::assertSame('passed', $result['checks']['file_digests']);
        self::assertFileExists($this->root . '/.agent-edit/receipts/fix/verification-result.json');
    }

    public function testVerifyWithoutGitIsIncompleteBecauseScopeIsUnproven(): void
    {
        $this->apply($this->fixPlan(), 'fix');

        $execution = $this->json('.agent-edit/receipts/fix/execution.json');
        self::assertSame('unavailable', $execution['changed_files_source']);

        $result = (new EditEngine())->verify($this->root, '.agent-edit/receipts/fix');
        self::assertSame('incomplete', $result['status']);
        self::assertSame('scope_unproven', $result['scope']['status']);
        self::assertSame('passed', $result['checks']['file_digests']);
        self::assertSame('unobserved', $result['checks']['changed_files']);
    }

    public function testVerifyAcceptsALaterUnrelatedChangeAndSaysTheFileWasSuperseded(): void
    {
        $this->initGit();
        $this->apply($this->fixPlan(), 'fix');
        file_put_contents($this->root . '/src/Calc.php', $this->read('src/Calc.php') . "\n// later\n");

        $result = (new EditEngine())->verify($this->root, '.agent-edit/receipts/fix');

        self::assertSame('passed', $result['status']);
        self::assertSame('superseded_effect_present', $result['checks']['file_digests']);
        self::assertSame(['src/Calc.php'], $result['files_changed_by_later_edits']);
    }

    public function testVerifyFailsWhenALaterChangeUndidTheEditsEffect(): void
    {
        $this->initGit();
        $this->apply($this->fixPlan(), 'fix');
        file_put_contents($this->root . '/src/Calc.php', str_replace('return $a + $b;', 'return $a - $b; // reverted', $this->read('src/Calc.php')));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("this edit's effect is no longer present: src/Calc.php");

        (new EditEngine())->verify($this->root, '.agent-edit/receipts/fix');
    }

    public function testVerifyFailsWhenALaterChangeAlsoDestroyedThePreImageEvidence(): void
    {
        $this->initGit();
        $this->apply($this->fixPlan(), 'fix');
        file_put_contents($this->root . '/src/Calc.php', $this->read('src/Calc.php') . "\n// later\n");
        unlink($this->root . '/.agent-edit/receipts/fix/before/src/Calc.php');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('pre-image is missing or altered, so the edit cannot be proven');

        (new EditEngine())->verify($this->root, '.agent-edit/receipts/fix');
    }

    public function testEarlierBundlesStillVerifyAfterLaterQuickFixesToTheSameFile(): void
    {
        $this->initGit();
        $second = ['type' => 'source_edit_plan', 'contract_version' => '1.0', 'target_id' => 'second', 'edits' => [['path' => 'src/Calc.php', 'find' => 'public function add', 'replace' => 'public function add']]];
        $second['edits'][0] = ['path' => 'src/Calc.php', 'find' => '{' . "\n" . '        return $a + $b;', 'replace' => '{' . "\n" . '        // sum' . "\n" . '        return $a + $b;'];
        $this->apply($this->fixPlan(), 'first');
        $this->apply($second, 'second');

        $engine = new EditEngine();
        $first = $engine->verify($this->root, '.agent-edit/receipts/first');
        self::assertSame('passed', $first['status']);
        self::assertSame(['src/Calc.php'], $first['files_changed_by_later_edits']);
        $latest = $engine->verify($this->root, '.agent-edit/receipts/second');
        self::assertSame('passed', $latest['status']);
        self::assertSame([], $latest['files_changed_by_later_edits']);
        self::assertSame('passed', $latest['checks']['file_digests']);
    }

    public function testASupersededDeletionIsProvenByTheDeletedTextStayingGone(): void
    {
        $this->initGit();
        $this->apply(['type' => 'source_edit_plan', 'contract_version' => '1.0', 'target_id' => 'del', 'edits' => [['path' => 'src/Calc.php', 'find' => "declare(strict_types=1);\n\n", 'replace' => '']]], 'del');
        file_put_contents($this->root . '/src/Calc.php', $this->read('src/Calc.php') . "\n// later\n");
        self::assertSame('passed', (new EditEngine())->verify($this->root, '.agent-edit/receipts/del')['status']);

        file_put_contents($this->root . '/src/Calc.php', str_replace('namespace Demo;', "declare(strict_types=1);\n\nnamespace Demo;", $this->read('src/Calc.php')));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('effect is no longer present');
        (new EditEngine())->verify($this->root, '.agent-edit/receipts/del');
    }

    public function testASupersededCreatedFileOnlyHasToStillExist(): void
    {
        $this->initGit();
        $plan = $this->fixPlan();
        $plan['creates'] = [['path' => 'notes.md', 'content' => "first\n"]];
        $this->apply($plan, 'created');
        file_put_contents($this->root . '/notes.md', "first\nsecond author\n");

        self::assertSame('passed', (new EditEngine())->verify($this->root, '.agent-edit/receipts/created')['status']);

        unlink($this->root . '/notes.md');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('missing after execution: notes.md');
        (new EditEngine())->verify($this->root, '.agent-edit/receipts/created');
    }

    public function testVerifyDetectsATamperedBoundPlan(): void
    {
        $this->initGit();
        $this->apply($this->fixPlan(), 'fix');
        file_put_contents($this->root . '/.agent-edit/receipts/fix/plan.json', '{"tampered":true}');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Bound edit plan changed after execution');

        (new EditEngine())->verify($this->root, '.agent-edit/receipts/fix');
    }

    public function testVerifyDetectsABrokenPhpFileEvenWhenTheDigestWasForged(): void
    {
        $this->initGit();
        $this->apply($this->fixPlan(), 'fix');
        $broken = "<?php\nfinal class {\n";
        file_put_contents($this->root . '/src/Calc.php', $broken);
        $path = $this->root . '/.agent-edit/receipts/fix/execution.json';
        $execution = $this->json('.agent-edit/receipts/fix/execution.json');
        $execution['direct_edit']['files'][0]['after_sha256'] = hash('sha256', $broken);
        file_put_contents($path, json_encode($execution, JSON_THROW_ON_ERROR));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no longer parses');

        (new EditEngine())->verify($this->root, '.agent-edit/receipts/fix');
    }

    public function testVerifyDetectsAnExtraChangedFileOutsideThePlan(): void
    {
        $this->initGit();
        $this->apply($this->fixPlan(), 'fix');
        $path = $this->root . '/.agent-edit/receipts/fix/execution.json';
        $execution = $this->json('.agent-edit/receipts/fix/execution.json');
        $execution['changed_files'][] = 'src/Sneaky.php';
        file_put_contents($path, json_encode($execution, JSON_THROW_ON_ERROR));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('do not exactly match plan scope');

        (new EditEngine())->verify($this->root, '.agent-edit/receipts/fix');
    }

    public function testVerifyRefusesADryRunBundle(): void
    {
        $this->apply($this->fixPlan(), 'dry', dryRun: true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('successfully applied non-dry execution');

        (new EditEngine())->verify($this->root, '.agent-edit/receipts/dry');
    }

    public function testDryRunWritesNothingButAPreparedReceipt(): void
    {
        $this->initGit();
        $receipt = $this->apply($this->fixPlan(), 'dry', dryRun: true);

        self::assertSame('prepared', $receipt->status);
        self::assertSame(self::SOURCE, $this->read('src/Calc.php'));
        $execution = $this->json('.agent-edit/receipts/dry/execution.json');
        self::assertTrue($execution['runner']['dry_run']);
        self::assertArrayNotHasKey('direct_edit', $execution);
        self::assertDirectoryDoesNotExist($this->root . '/.agent-edit/receipts/dry/before');
    }

    public function testRefusedPlanLeavesNoBundleAndNeverReachesAuthorization(): void
    {
        $authorized = false;
        $plan = $this->fixPlan();
        $plan['edits'][0]['find'] = 'this text is not in the file';

        try {
            $this->apply($plan, 'refused', authorize: static function () use (&$authorized): void {
                $authorized = true;
            }, planInBundle: false);
            self::fail('An unprovable edit must be refused.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('does not occur', $exception->getMessage());
        }

        self::assertFalse($authorized, 'Validation must run before the host is asked to authorize a write.');
        self::assertDirectoryDoesNotExist($this->root . '/.agent-edit/receipts/refused');
        self::assertSame(self::SOURCE, $this->read('src/Calc.php'));
    }

    public function testHostAuthorizationRefusalWritesNothing(): void
    {
        try {
            $this->apply($this->fixPlan(), 'denied', authorize: static function (): void {
                throw new RuntimeException('not allowed');
            }, planInBundle: false);
            self::fail('The authorization refusal must surface.');
        } catch (RuntimeException $exception) {
            self::assertSame('not allowed', $exception->getMessage());
        }

        self::assertSame(self::SOURCE, $this->read('src/Calc.php'));
        self::assertDirectoryDoesNotExist($this->root . '/.agent-edit/receipts/denied');
    }

    public function testConcurrentUnrelatedEditBetweenAuthorizationAndWriteIsPreserved(): void
    {
        $this->initGit();
        $request = new ApplyRequest(
            repositoryRoot: $this->root,
            planPath: $this->writePlan($this->fixPlan(), 'race'),
            mapIndexPath: null,
            mapRoot: $this->root,
            outputDirectory: $this->root . '/.agent-edit/receipts/race',
            label: 'race',
            // Another process appends to the file after validation but before the write.
            authorizeMutation: function (): void {
                file_put_contents($this->root . '/src/Calc.php', self::SOURCE . "\n// concurrent edit\n");
            },
        );

        (new EditEngine())->applyWithReceipt($request);

        // The anchor is re-resolved under the lock against the new bytes, so the edit lands on top of the concurrent
        // change instead of overwriting it, and the receipt records the digest of what was really published.
        $source = $this->read('src/Calc.php');
        self::assertStringContainsString('// concurrent edit', $source);
        self::assertStringContainsString('return $a + $b;', $source);
        $execution = $this->json('.agent-edit/receipts/race/execution.json');
        self::assertSame(hash('sha256', $source), $execution['direct_edit']['files'][0]['after_sha256']);
        self::assertSame(hash('sha256', self::SOURCE . "\n// concurrent edit\n"), $execution['direct_edit']['files'][0]['before_sha256']);
        self::assertSame('passed', (new EditEngine())->verify($this->root, '.agent-edit/receipts/race')['status']);
    }

    public function testFailureUnderTheLockKeepsAFailureReceiptWithoutDirectEditEvidence(): void
    {
        $this->initGit();
        $plan = $this->fixPlan();
        $request = new ApplyRequest(
            repositoryRoot: $this->root,
            planPath: $this->writePlan($plan, 'late'),
            mapIndexPath: null,
            mapRoot: $this->root,
            outputDirectory: $this->root . '/.agent-edit/receipts/late',
            label: 'late',
            authorizeMutation: function (): void {
                // The anchor disappears after preflight succeeded but before the write: a real mutation attempt that fails.
                file_put_contents($this->root . '/src/Calc.php', str_replace('return $a - $b;', 'return 0;', self::SOURCE));
            },
        );

        try {
            (new EditEngine())->applyWithReceipt($request);
            self::fail('The edit must fail once its anchor is gone.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('does not occur', $exception->getMessage());
        }

        $execution = $this->json('.agent-edit/receipts/late/execution.json');
        self::assertSame('runner_failed', $execution['status']);
        self::assertSame(1, $execution['runner']['exit_code']);
        self::assertArrayNotHasKey('direct_edit', $execution);
        self::assertStringContainsString('return 0;', $this->read('src/Calc.php'));
    }

    public function testReceiptWriteFailureAfterPublicationIsReportedAsUnrecordedChange(): void
    {
        $this->initGit();
        $bundle = $this->root . '/.agent-edit/receipts/blocked';
        mkdir(dirname($bundle), 0o775, true);
        file_put_contents($bundle, 'a file where the bundle directory should be');

        try {
            (new EditEngine())->applyWithReceipt(new ApplyRequest(
                repositoryRoot: $this->root,
                planPath: $this->writePlan($this->fixPlan(), 'blocked-plan'),
                mapIndexPath: null,
                mapRoot: $this->root,
                outputDirectory: $bundle,
                label: 'blocked',
            ));
            self::fail('A blocked bundle directory must surface.');
        } catch (ReceiptNotPersistedException|RuntimeException $exception) {
            self::assertNotSame('', $exception->getMessage());
        }
    }

    public function testEngineApiPreflightAndApplyAcceptNullMapForDirectPlans(): void
    {
        $engine = new EditEngine();
        $prepared = $engine->preflight($this->fixPlan(), null, $this->root);

        self::assertSame('source_edit_plan', $prepared['plan_type']);
        self::assertSame(self::SOURCE, $this->read('src/Calc.php'));

        $result = $engine->apply($this->fixPlan(), null, $this->root);
        self::assertTrue($result->succeeded());
        self::assertStringContainsString('return $a + $b;', $this->read('src/Calc.php'));
    }

    public function testMapPlansStillRefuseWithoutAMap(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('needs an agent-map index');

        (new EditEngine())->preflight(['type' => 'method_removal_plan', 'contract_version' => '1.0'], null, $this->root);
    }

    public function testMapPlanReceiptRequestWithoutMapIndexIsRefused(): void
    {
        $plan = $this->root . '/map-plan.json';
        file_put_contents($plan, json_encode(['type' => 'method_removal_plan', 'contract_version' => '1.0']));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('needs an agent-map index');

        (new EditEngine())->applyWithReceipt(new ApplyRequest($this->root, $plan, null, $this->root, $this->root . '/.agent-edit/receipts/x', 'x'));
    }

    public function testUndoRestoresPreImagesAndRemovesCreatedFiles(): void
    {
        $this->initGit();
        $plan = $this->fixPlan();
        $plan['creates'] = [['path' => 'tests/new/CalcTest.php', 'content' => "<?php\n\nfinal class CalcTest {}\n"]];
        $this->apply($plan, 'multi');
        self::assertFileExists($this->root . '/tests/new/CalcTest.php');

        $result = (new EditEngine())->undo($this->root, '.agent-edit/receipts/multi');

        self::assertSame('undone', $result['status']);
        self::assertSame(['src/Calc.php'], $result['restored']);
        self::assertSame(['tests/new/CalcTest.php'], $result['removed']);
        self::assertSame(self::SOURCE, $this->read('src/Calc.php'));
        self::assertDirectoryDoesNotExist($this->root . '/tests');
        self::assertFileExists($this->root . '/.agent-edit/receipts/multi/undo-result.json');
        self::assertSame([], $this->temporaryArtifacts());
    }

    public function testUndoRefusesWhenAFileChangedAfterTheEditAndTouchesNothing(): void
    {
        $this->initGit();
        $plan = $this->fixPlan();
        $plan['creates'] = [['path' => 'extra.txt', 'content' => "x\n"]];
        $this->apply($plan, 'multi');
        file_put_contents($this->root . '/extra.txt', "edited later\n");

        try {
            (new EditEngine())->undo($this->root, '.agent-edit/receipts/multi');
            self::fail('Later edits must never be overwritten.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('extra.txt', $exception->getMessage());
        }

        self::assertStringContainsString('return $a + $b;', $this->read('src/Calc.php'), 'No file may be restored when any precondition fails.');
        self::assertSame("edited later\n", $this->read('extra.txt'));
        self::assertFileDoesNotExist($this->root . '/.agent-edit/receipts/multi/undo-result.json');
    }

    public function testUndoCannotRunTwiceAndMakesVerifyRefuse(): void
    {
        $this->initGit();
        $this->apply($this->fixPlan(), 'fix');
        $engine = new EditEngine();
        $engine->undo($this->root, '.agent-edit/receipts/fix');

        try {
            $engine->undo($this->root, '.agent-edit/receipts/fix');
            self::fail('A second undo must be refused.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('already undone', $exception->getMessage());
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('was undone');
        $engine->verify($this->root, '.agent-edit/receipts/fix');
    }

    public function testUndoRefusesATamperedPreImage(): void
    {
        $this->initGit();
        $this->apply($this->fixPlan(), 'fix');
        file_put_contents($this->root . '/.agent-edit/receipts/fix/before/src/Calc.php', "<?php\n// malicious\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('pre-image of src/Calc.php is missing or was altered');

        (new EditEngine())->undo($this->root, '.agent-edit/receipts/fix');
    }

    public function testUndoRefusesNonDirectBundlesAndTraversalInReceipts(): void
    {
        $this->initGit();
        $this->apply($this->fixPlan(), 'fix');
        $path = $this->root . '/.agent-edit/receipts/fix/execution.json';
        $execution = $this->json('.agent-edit/receipts/fix/execution.json');

        $traversal = $execution;
        $traversal['direct_edit']['files'][0]['path'] = '../outside.php';
        file_put_contents($path, json_encode($traversal, JSON_THROW_ON_ERROR));
        try {
            (new EditEngine())->undo($this->root, '.agent-edit/receipts/fix');
            self::fail('A traversal path in a receipt must be refused.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('non-canonical path', $exception->getMessage());
        }

        $foreign = $execution;
        $foreign['runner']['name'] = 'rename-plan';
        file_put_contents($path, json_encode($foreign, JSON_THROW_ON_ERROR));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Only a successfully applied source_edit_plan bundle');
        (new EditEngine())->undo($this->root, '.agent-edit/receipts/fix');
    }

    public function testUndoOfADryRunBundleIsRefused(): void
    {
        $this->apply($this->fixPlan(), 'dry', dryRun: true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Only a successfully applied');

        (new EditEngine())->undo($this->root, '.agent-edit/receipts/dry');
    }

    public function testChainedEditsUndoOnlyInReverseOrder(): void
    {
        $this->initGit();
        $first = $this->fixPlan();
        $second = ['type' => 'source_edit_plan', 'contract_version' => '1.0', 'target_id' => 'second', 'edits' => [['path' => 'src/Calc.php', 'find' => 'return $a + $b;', 'replace' => 'return $b + $a;']]];
        $this->apply($first, 'first');
        $this->apply($second, 'second');
        $engine = new EditEngine();

        try {
            $engine->undo($this->root, '.agent-edit/receipts/first');
            self::fail('The first edit cannot be undone underneath the second one.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('src/Calc.php', $exception->getMessage());
        }
        self::assertStringContainsString('return $b + $a;', $this->read('src/Calc.php'));

        $engine->undo($this->root, '.agent-edit/receipts/second');
        self::assertStringContainsString('return $a + $b;', $this->read('src/Calc.php'));
        $engine->undo($this->root, '.agent-edit/receipts/first');
        self::assertSame(self::SOURCE, $this->read('src/Calc.php'));
    }

    public function testUndoKeepsDirectoriesThatStillHoldOtherFiles(): void
    {
        $this->initGit();
        $plan = $this->fixPlan();
        $plan['creates'] = [['path' => 'tests/new/ATest.php', 'content' => "<?php\n"]];
        $this->apply($plan, 'created');
        file_put_contents($this->root . '/tests/new/Other.txt', "someone else's file\n");

        (new EditEngine())->undo($this->root, '.agent-edit/receipts/created');

        self::assertFileDoesNotExist($this->root . '/tests/new/ATest.php');
        self::assertSame("someone else's file\n", $this->read('tests/new/Other.txt'));
    }

    public function testTheLabelReachesTheAuthorizationHookOnlyForRealWrites(): void
    {
        $labels = [];
        $record = static function (string $label) use (&$labels): void {
            $labels[] = $label;
        };

        $this->apply($this->fixPlan(), 'dry', dryRun: true, authorize: $record);
        self::assertSame([], $labels, 'A dry run must never ask for write authorization.');

        $this->apply($this->fixPlan(), 'real', authorize: $record);
        self::assertSame(['real'], $labels);
    }

    public function testToonPlansAreAppliedAndVerifiedLikeJsonPlans(): void
    {
        $this->initGit();
        $dir = $this->root . '/.agent-edit/receipts/toon';
        mkdir($dir, 0o775, true);
        file_put_contents($dir . '/plan.toon', \HelgeSverre\Toon\Toon::encode($this->fixPlan()));

        (new EditEngine())->applyWithReceipt(new ApplyRequest($this->root, $dir . '/plan.toon', null, $this->root, $dir, 'toon'));

        self::assertStringContainsString('return $a + $b;', $this->read('src/Calc.php'));
        self::assertSame('passed', (new EditEngine())->verify($this->root, '.agent-edit/receipts/toon')['status']);
    }

    public function testVerifyRejectsReceiptsThatLostTheirDirectEditEvidence(): void
    {
        $this->initGit();
        $this->apply($this->fixPlan(), 'fix');
        $path = $this->root . '/.agent-edit/receipts/fix/execution.json';
        $execution = $this->json('.agent-edit/receipts/fix/execution.json');

        $stripped = $execution;
        unset($stripped['direct_edit']);
        file_put_contents($path, json_encode($stripped, JSON_THROW_ON_ERROR));
        try {
            (new EditEngine())->verify($this->root, '.agent-edit/receipts/fix');
            self::fail('A receipt without file evidence cannot verify.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('missing direct_edit file evidence', $exception->getMessage());
        }

        $swapped = $execution;
        $swapped['direct_edit']['files'][0]['path'] = 'src/Other.php';
        file_put_contents($path, json_encode($swapped, JSON_THROW_ON_ERROR));
        try {
            (new EditEngine())->verify($this->root, '.agent-edit/receipts/fix');
            self::fail('Evidence for another file cannot verify.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('missing after execution: src/Other.php', $exception->getMessage());
        }

        $failed = $execution;
        $failed['status'] = 'runner_failed';
        file_put_contents($path, json_encode($failed, JSON_THROW_ON_ERROR));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('successfully applied non-dry execution');
        (new EditEngine())->verify($this->root, '.agent-edit/receipts/fix');
    }

    public function testVerifyRejectsAReceiptForADifferentPlanIdentity(): void
    {
        $this->initGit();
        $this->apply($this->fixPlan(), 'fix');
        $path = $this->root . '/.agent-edit/receipts/fix/execution.json';
        $execution = $this->json('.agent-edit/receipts/fix/execution.json');
        $execution['plan']['target_id'] = 'someone-elses-plan';
        file_put_contents($path, json_encode($execution, JSON_THROW_ON_ERROR));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not bound to the loaded edit plan identity');

        (new EditEngine())->verify($this->root, '.agent-edit/receipts/fix');
    }

    /** @return array<string, mixed> */
    private function fixPlan(): array
    {
        return [
            'type' => 'source_edit_plan',
            'contract_version' => '1.0',
            'target_id' => 'fix-add',
            'edits' => [['path' => 'src/Calc.php', 'find' => 'return $a - $b;', 'replace' => 'return $a + $b;']],
        ];
    }

    /**
     * @param array<string, mixed> $plan
     * @param (\Closure(string): void)|null $authorize
     */
    private function apply(array $plan, string $label, bool $dryRun = false, ?\Closure $authorize = null, bool $planInBundle = true): \voku\AgentEdit\Receipt\EditReceipt
    {
        return (new EditEngine())->applyWithReceipt(new ApplyRequest(
            repositoryRoot: $this->root,
            planPath: $planInBundle ? $this->writePlan($plan, $label) : $this->writeLoosePlan($plan, $label),
            mapIndexPath: null,
            mapRoot: $this->root,
            outputDirectory: $this->root . '/.agent-edit/receipts/' . $label,
            label: $label,
            dryRun: $dryRun,
            authorizeMutation: $authorize,
        ));
    }

    /** @param array<string, mixed> $plan */
    private function writePlan(array $plan, string $label): string
    {
        $dir = $this->root . '/.agent-edit/receipts/' . $label;
        if (!is_dir($dir)) {
            mkdir($dir, 0o775, true);
        }
        $path = $dir . '/plan.json';
        file_put_contents($path, json_encode($plan, JSON_THROW_ON_ERROR));

        return $path;
    }

    /** @param array<string, mixed> $plan */
    private function writeLoosePlan(array $plan, string $label): string
    {
        $path = $this->root . '/plan-' . $label . '.json';
        file_put_contents($path, json_encode($plan, JSON_THROW_ON_ERROR));

        return $path;
    }

    private function initGit(): void
    {
        $run = fn (string $command) => shell_exec('cd ' . escapeshellarg($this->root) . ' && ' . $command . ' 2>&1');
        $run('git init -q');
        $run('git config user.email test@example.invalid');
        $run('git config user.name test');
        file_put_contents($this->root . '/.gitignore', ".agent-edit/\n");
        $run('git add -A && git commit -qm init');
    }

    private function read(string $relative): string
    {
        return (string) file_get_contents($this->root . '/' . $relative);
    }

    /** @return array<string, mixed> */
    private function json(string $relative): array
    {
        $decoded = json_decode($this->read($relative), true, 512, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }

    /** @return list<string> */
    private function temporaryArtifacts(): array
    {
        $found = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($iterator as $item) {
            if (str_contains($item->getFilename(), 'agent-edit-tx-')) {
                $found[] = $item->getPathname();
            }
        }

        return $found;
    }
}
