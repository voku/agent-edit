<?php

declare(strict_types=1);

namespace voku\AgentEdit\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentEdit\Apply\ClassScaffoldPlanApplier;
use voku\AgentEdit\EditEngine;
use voku\AgentEdit\Failure\CodedFailure;
use voku\AgentEdit\Failure\FailureCode;
use voku\AgentEdit\Receipt\ApplyRequest;
use voku\AgentEdit\Receipt\EditReceipt;
use voku\AgentEdit\Tests\Support\CachedAgentMapBuilder;
use voku\AgentEdit\Tests\Support\CapturesFailures;
use voku\AgentEdit\Tests\Support\ScratchProject;
use voku\AgentEdit\Tests\Support\UnifiedPatch;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\IndexWriter;
use voku\AgentMap\Scaffold\ClassScaffoldPlanner;

/** class_scaffold_plan@1.0: one new file, nothing existing touched, nothing left behind on failure. */
final class ClassScaffoldPlanTest extends TestCase
{
    use CapturesFailures;
    use ScratchProject;

    protected function setUp(): void
    {
        $this->createScratchProject('agent-edit-class-scaffold-');
        $this->write('composer.json', json_encode(['autoload' => ['psr-4' => ['Demo\\' => 'src/']]], JSON_THROW_ON_ERROR));
        $this->write('src/Existing.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace Demo;\n\nfinal class Existing\n{\n}\n");
    }

    protected function tearDown(): void
    {
        $this->removeScratchProject();
    }

    private function map(): AgentMapIndex
    {
        return CachedAgentMapBuilder::build($this->root, ['src'], []);
    }

    /**
     * @param list<string> $implements
     * @return array<string, mixed>
     */
    private function plan(string $fqn = 'Demo\\Deep\\New\\Thing', string $type = 'class', bool $final = true, bool $readonly = false, ?string $extends = null, array $implements = [], ?string $dest = null): array
    {
        return (new ClassScaffoldPlanner())->plan($this->map(), $fqn, $type, $final, $readonly, $extends, $implements, 'A new thing.', $dest)->toArray();
    }

    /** @param array<string, mixed> $plan */
    private function apply(array $plan, bool $dryRun = false, string $label = 'SCAFFOLD'): EditReceipt
    {
        $map = $this->map();
        (new IndexWriter())->write($map, $this->root . '/map.json');
        file_put_contents($this->root . '/plan.json', json_encode($plan, JSON_THROW_ON_ERROR));

        return (new EditEngine())->applyWithReceipt(new ApplyRequest($this->root, $this->root . '/plan.json', $this->root . '/map.json', $this->root, $this->root . '/.agent-edit/receipts/' . $label, $label, dryRun: $dryRun));
    }

    private function gitInit(): void
    {
        $this->write('.gitignore', "map.json\nplan.json\n.agent-edit/\n.agent-map/\n");
        exec('cd ' . escapeshellarg($this->root) . ' && git init -q . && git add -A && git -c user.email=t@example.invalid -c user.name=t commit -qm init', $out, $exit);
        self::assertSame(0, $exit, implode("\n", $out));
    }

    /** @param array<string, mixed> $plan */
    private function assertRefused(array $plan, string $message, ?FailureCode $code = null): void
    {
        $failure = $this->capturedOrFail(fn () => $this->apply($plan, dryRun: true, label: 'REFUSED'), $message);

        self::assertStringContainsString($message, $failure->getMessage());
        if ($code !== null) {
            self::assertInstanceOf(CodedFailure::class, $failure);
            self::assertSame($code, $failure->failureCode());
        }
        self::assertDirectoryDoesNotExist($this->root . '/src/Deep', 'a refusal creates nothing');
        self::assertDirectoryDoesNotExist($this->root . '/.agent-edit', 'a refusal leaves no bundle');
    }

    public function testDryRunShowsTheNewFileAndCreatesNothingNotEvenDirectories(): void
    {
        $plan = $this->plan();
        self::assertSame('safe', $plan['status']);

        $dry = $this->apply($plan, dryRun: true, label: 'DRY');

        self::assertSame('prepared', $dry->status);
        self::assertDirectoryDoesNotExist($this->root . '/src/Deep');
        self::assertSame([['path' => 'src/Deep/New/Thing.php', 'change' => 'created', 'lines_added' => 12, 'lines_removed' => 0]], $dry->plannedChanges);
        self::assertStringStartsWith("--- /dev/null\n+++ b/src/Deep/New/Thing.php\n@@ -0,0 +1,12 @@\n+<?php\n", $dry->diff);
        self::assertSame($plan['edits'][0]['replacement'], UnifiedPatch::apply('', $dry->diff));
        $receipt = json_decode($this->read('.agent-edit/receipts/DRY/execution.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('created', $receipt['planned_changes'][0]['change']);
    }

    public function testApplyCreatesTheFileAndItsMissingDirectoriesWithExactlyThePlannedBytes(): void
    {
        $this->gitInit();
        $plan = $this->plan();

        $real = $this->apply($plan, label: 'CREATE');

        self::assertSame($plan['edits'][0]['replacement'], $this->read('src/Deep/New/Thing.php'));
        self::assertSame(['src/Deep/New/Thing.php'], $real->changedFiles);
        self::assertSame([], glob($this->root . '/src/Deep/New/*.agent-edit-plan-*'), 'no staging file is left behind');
        self::assertSame(0, $this->lint('src/Deep/New/Thing.php'));
        self::assertSame(0o664 & ~umask(), fileperms($this->root . '/src/Deep/New/Thing.php') & 0o777, 'a new file follows the umask like any file the user creates');

        (new IndexWriter())->write($this->map(), $this->root . '/map.json');
        $result = (new EditEngine())->verify($this->root, '.agent-edit/receipts/CREATE', $this->root . '/map.json');
        self::assertSame('passed', $result['status']);
        self::assertSame('class_scaffold_plan_verification', $result['kind']);
        self::assertSame('passed', $result['checks']['content']);
    }

    public function testWithoutGitTheCreationIsStillObservedThroughTheMapScope(): void
    {
        $plan = $this->plan();

        $real = $this->apply($plan, label: 'NOGIT');

        $receipt = json_decode($this->read('.agent-edit/receipts/NOGIT/execution.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('map_manifest_diff', $receipt['changed_files_source']);
        self::assertSame(['src/Deep/New/Thing.php'], $receipt['changed_files'], 'the absence before and the file after are both observed');
        self::assertSame(['src/Deep/New/Thing.php'], $real->changedFiles);

        (new IndexWriter())->write($this->map(), $this->root . '/map.json');
        $result = (new EditEngine())->verify($this->root, '.agent-edit/receipts/NOGIT', $this->root . '/map.json');
        self::assertSame('incomplete', $result['status'], 'Git-free evidence is scope_unproven, never passed');
    }

    /**
     * @dataProvider declarationKinds
     *
     * @param list<string> $implements
     */
    public function testEveryKindTheMapCanScaffoldIsAcceptedAndVerifies(string $type, bool $final, bool $readonly, ?string $extends, array $implements, string $needle): void
    {
        $this->gitInit();
        $plan = $this->plan('Demo\\Kinds\\Made', $type, $final, $readonly, $extends, $implements);
        self::assertSame('safe', $plan['status']);

        $this->apply($plan, label: 'KIND');

        self::assertStringContainsString($needle, $this->read('src/Kinds/Made.php'));
        self::assertSame(0, $this->lint('src/Kinds/Made.php'));
        (new IndexWriter())->write($this->map(), $this->root . '/map.json');
        self::assertSame('passed', (new EditEngine())->verify($this->root, '.agent-edit/receipts/KIND', $this->root . '/map.json')['status']);
    }

    /** @return iterable<string, array{string, bool, bool, ?string, list<string>, string}> */
    public static function declarationKinds(): iterable
    {
        yield 'final class' => ['class', true, false, null, [], 'final class Made'];
        yield 'open class' => ['class', false, false, null, [], "\nclass Made"];
        yield 'readonly class' => ['class', true, true, null, [], 'final readonly class Made'];
        yield 'extends and implements' => ['class', true, false, 'Demo\\Existing', ['\\Countable', '\\Stringable'], 'extends'];
        yield 'interface' => ['interface', false, false, null, [], 'interface Made'];
        yield 'trait' => ['trait', false, false, null, [], 'trait Made'];
        yield 'enum' => ['enum', false, false, null, [], 'enum Made'];
    }

    public function testAnExistingFileIsNeverOverwritten(): void
    {
        // Outside the indexed `src`, so creating it does not change the Map the plan is bound to.
        $plan = $this->plan('Demo\\Fresh', dest: 'lib/Fresh.php');
        $this->write('lib/Fresh.php', "<?php // someone else's file\n");
        $before = $this->read('lib/Fresh.php');

        $failure = $this->capturedOrFail(fn () => $this->apply($plan, label: 'EXISTS'), 'an existing destination');

        self::assertInstanceOf(CodedFailure::class, $failure);
        self::assertSame(FailureCode::TargetExists, $failure->failureCode());
        self::assertSame(['path' => 'lib/Fresh.php'], $failure->details());
        self::assertSame($before, $this->read('lib/Fresh.php'));
    }

    public function testADanglingSymlinkAtTheDestinationCountsAsOccupied(): void
    {
        $plan = $this->plan('Demo\\Fresh', dest: 'lib/Fresh.php');
        mkdir($this->root . '/lib');
        symlink($this->root . '/does-not-exist', $this->root . '/lib/Fresh.php');

        $this->expectException(CodedFailure::class);
        $this->expectExceptionMessage('Class scaffold destination already exists: lib/Fresh.php');
        $this->apply($plan, label: 'DANGLING');
    }

    public function testAParentThatIsAFileIsRefused(): void
    {
        $plan = $this->plan('Demo\\Fresh', dest: 'lib/parent.txt/Fresh.php');
        $this->write('lib/parent.txt', "not a directory\n");

        $this->assertRefused($plan, 'Parent of a created file is not a directory');
    }

    public function testASymlinkedParentThatLeavesTheRootIsRefused(): void
    {
        $outside = sys_get_temp_dir() . '/agent-edit-outside-' . bin2hex(random_bytes(4));
        mkdir($outside);
        try {
            mkdir($this->root . '/lib');
            symlink($outside, $this->root . '/lib/Linked');
            $plan = $this->plan('Demo\\Linked\\Fresh', dest: 'lib/Linked/Fresh.php');

            $this->assertRefused($plan, 'Parent of a created file escapes the project root');
            self::assertSame([], glob($outside . '/*'), 'nothing was written outside the root');
        } finally {
            @unlink($this->root . '/lib/Linked');
            @rmdir($outside);
        }
    }

    public function testAnAlreadyDeclaredClassIsRefusedEvenIfTheForgedPlanIsSafe(): void
    {
        $plan = $this->plan('Demo\\Elsewhere\\Made');
        // Forge the plan so it names a class the Map already knows, as a stale or hostile plan could.
        $forged = $plan;
        $forged['fqn'] = 'Demo\\Existing';
        $forged['target_id'] = 'class:Demo\\Existing';
        $forged['edits'][0]['symbol_id'] = 'Demo\\Existing';
        $forged['edits'][0]['replacement'] = str_replace('Made', 'Existing', str_replace('namespace Demo\\Elsewhere;', 'namespace Demo;', $forged['edits'][0]['replacement']));

        $this->assertRefused($forged, 'already declared in src/Existing.php', FailureCode::EvidenceChanged);
    }

    public function testAStaleMapIsRefusedAsStale(): void
    {
        $plan = $this->plan();
        $map = $this->map();
        (new IndexWriter())->write($map, $this->root . '/map.json');
        $this->write('src/Existing.php', $this->read('src/Existing.php') . "\n// edited after the map\n");
        file_put_contents($this->root . '/plan.json', json_encode($plan, JSON_THROW_ON_ERROR));

        $failure = $this->capturedOrFail(
            fn () => (new EditEngine())->applyWithReceipt(new ApplyRequest($this->root, $this->root . '/plan.json', $this->root . '/map.json', $this->root, $this->root . '/.agent-edit/receipts/STALE', 'STALE')),
            'a stale map',
        );

        self::assertInstanceOf(CodedFailure::class, $failure);
        self::assertSame(FailureCode::EvidenceStale, $failure->failureCode());
        self::assertDirectoryDoesNotExist($this->root . '/src/Deep');
    }

    public function testAPlanMadeAgainstAnotherMapIsStale(): void
    {
        $plan = $this->plan();
        $this->write('src/Another.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace Demo;\n\nfinal class Another\n{\n}\n");

        $this->assertRefused($plan, 'does not match the current map identity', FailureCode::EvidenceStale);
    }

    /** @dataProvider forgedPlans */
    public function testForgedPlansAreRefusedBeforeAnyChange(callable $forge, string $message): void
    {
        $this->assertRefused($forge($this->plan()), $message);
    }

    /** @return iterable<string, array{callable(array<string, mixed>): array<string, mixed>, string}> */
    public static function forgedPlans(): iterable
    {
        $edit = static fn (callable $change): callable => static function (array $plan) use ($change): array {
            $plan['edits'][0] = $change($plan['edits'][0]);

            return $plan;
        };
        $plan = static fn (callable $change): callable => static fn (array $plan): array => $change($plan);

        yield 'blocked' => [$plan(static fn (array $p): array => ['status' => 'blocked', 'blockers' => ['no PSR-4']] + $p), 'Class scaffold plan is not safe'];
        yield 'blocker on a safe plan' => [$plan(static function (array $p): array {
            $p['blockers'] = ['x'];

            return $p;
        }), 'requires empty blockers evidence'];
        yield 'blind spot' => [$plan(static function (array $p): array {
            $p['blind_spots'] = [['kind' => 'x']];

            return $p;
        }), 'requires empty blind_spots evidence'];
        yield 'moves' => [$plan(static function (array $p): array {
            $p['moves'] = [['from_path' => 'a', 'to_path' => 'b']];

            return $p;
        }), 'may not publish file moves'];
        yield 'two edits' => [$plan(static function (array $p): array {
            $p['edits'][] = $p['edits'][0];

            return $p;
        }), 'exactly one file creation edit'];
        yield 'no edits' => [$plan(static function (array $p): array {
            $p['edits'] = [];

            return $p;
        }), 'exactly one file creation edit'];
        yield 'wrong role' => [$edit(static fn (array $e): array => ['role' => 'method_declaration_insertion'] + $e), 'not bound to the exact class identity'];
        yield 'wrong resolution' => [$edit(static fn (array $e): array => ['resolution' => 'copy_declaration'] + $e), 'not bound to the exact class identity'];
        yield 'another symbol' => [$edit(static fn (array $e): array => ['symbol_id' => 'Demo\\Other'] + $e), 'not bound to the exact class identity'];
        yield 'another path' => [$edit(static fn (array $e): array => ['path' => 'src/Elsewhere.php'] + $e), 'not bound to the exact class identity'];
        yield 'claims a source hash' => [$edit(static fn (array $e): array => ['source_sha256' => 'sha256:' . str_repeat('a', 64)] + $e), 'may not claim to replace existing bytes'];
        yield 'claims expected bytes' => [$edit(static fn (array $e): array => ['expected' => '<?php'] + $e), 'may not claim to replace existing bytes'];
        yield 'claims an offset' => [$edit(static fn (array $e): array => ['start_file_pos' => 1, 'end_file_pos' => 1] + $e), 'may not claim to replace existing bytes'];
        yield 'empty content' => [$edit(static fn (array $e): array => ['replacement' => ''] + $e), 'complete file content'];
        yield 'target names another class' => [$plan(static function (array $p): array {
            $p['target_id'] = 'class:Demo\\Other';

            return $p;
        }), 'target identity must name the scaffolded class'];
        yield 'prefixed fqn' => [$plan(static function (array $p): array {
            $p['fqn'] = '\\Demo\\Deep\\New\\Thing';

            return $p;
        }), 'exact, unprefixed class identity'];
        yield 'absolute destination' => [$plan(static function (array $p): array {
            $p['destination_path'] = '/etc/Thing.php';

            return $p;
        }), 'project-relative path'];
        yield 'parent traversal' => [$plan(static function (array $p): array {
            $p['destination_path'] = 'src/../../Thing.php';

            return $p;
        }), 'not a canonical relative path'];
        yield 'backslash path' => [$plan(static function (array $p): array {
            $p['destination_path'] = 'src\\Thing.php';

            return $p;
        }), 'project-relative path'];
        yield 'drive path' => [$plan(static function (array $p): array {
            $p['destination_path'] = 'C:Thing.php';

            return $p;
        }), 'project-relative path'];
        yield 'not php' => [$plan(static function (array $p): array {
            $p['destination_path'] = 'src/Thing.txt';
            $p['edits'][0]['path'] = 'src/Thing.txt';

            return $p;
        }), 'must be a .php file'];
        yield 'top-level code' => [$edit(static fn (array $e): array => ['replacement' => $e['replacement'] . "\nsystem('id');\n"] + $e), 'found top-level code'];
        yield 'top-level code before' => [$edit(static fn (array $e): array => ['replacement' => str_replace("namespace", "echo 1;\nnamespace", $e['replacement'])] + $e), 'found top-level code'];
        yield 'second class' => [$edit(static fn (array $e): array => ['replacement' => $e['replacement'] . "\nfinal class Second\n{\n}\n"] + $e), 'exactly the type Thing'];
        yield 'wrong class name' => [$edit(static fn (array $e): array => ['replacement' => str_replace('class Thing', 'class Other', $e['replacement'])] + $e), 'exactly the type Thing'];
        yield 'wrong namespace' => [$edit(static fn (array $e): array => ['replacement' => str_replace('namespace Demo\\Deep\\New;', 'namespace Evil;', $e['replacement'])] + $e), 'must declare namespace Demo\\Deep\\New'];
        yield 'no namespace' => [$edit(static fn (array $e): array => ['replacement' => str_replace("namespace Demo\\Deep\\New;\n\n", '', $e['replacement'])] + $e), 'must declare namespace Demo\\Deep\\New'];
        yield 'no open tag' => [$edit(static fn (array $e): array => ['replacement' => ltrim($e['replacement'], "<?php\n")] + $e), 'must start with a PHP open tag'];
        yield 'does not parse' => [$edit(static fn (array $e): array => ['replacement' => $e['replacement'] . "\nfinal class {\n"] + $e), 'does not parse'];
        yield 'unsupported version' => [$plan(static function (array $p): array {
            $p['contract_version'] = '2.0';

            return $p;
        }), 'Unsupported plan type or contract version'];
    }

    public function testADestinationThatAppearsDuringPublicationIsNeverReplacedAndNothingElseRemains(): void
    {
        $plan = $this->plan('Demo\\Race\\Made');
        mkdir($this->root . '/src/Race');
        $destination = $this->root . '/src/Race/Made.php';
        $applier = new ClassScaffoldPlanApplier(lintOperation: static function (string $path) use ($destination): array {
            file_put_contents($destination, "<?php // created by another process\n");

            return ['exit_code' => 0, 'stdout' => '', 'stderr' => ''];
        });

        $failure = $this->capturedOrFail(fn () => $applier->apply($plan, $this->map(), $this->root), 'a destination that appears during publication');

        self::assertStringContainsString('Made.php', $failure->getMessage());
        self::assertSame("<?php // created by another process\n", file_get_contents($destination), 'the other process\'s file is untouched');
        self::assertSame([], glob($this->root . '/src/Race/*.agent-edit-plan-*'), 'the staging file is removed');
    }

    public function testAFailedPublicationRemovesTheStagingFileAndTheDirectoriesItCreated(): void
    {
        $plan = $this->plan();
        $applier = new ClassScaffoldPlanApplier(renameOperation: static fn (string $from, string $to): bool => false);

        $failure = $this->capturedOrFail(fn () => $applier->apply($plan, $this->map(), $this->root), 'a failed publication');

        self::assertInstanceOf(RuntimeException::class, $failure);

        self::assertDirectoryDoesNotExist($this->root . '/src/Deep');
        self::assertFileDoesNotExist($this->root . '/src/Deep/New/Thing.php');
    }

    public function testAFailedSyntaxCheckPublishesNothingAndLeavesNoDirectories(): void
    {
        $plan = $this->plan();
        $applier = new ClassScaffoldPlanApplier(lintOperation: static fn (string $path): array => ['exit_code' => 255, 'stdout' => '', 'stderr' => 'Parse error: boom']);

        $failure = $this->capturedOrFail(fn () => $applier->apply($plan, $this->map(), $this->root), 'a failed syntax check');

        self::assertStringContainsString('syntax validation', $failure->getMessage());
        self::assertStringContainsString('Parse error: boom', $failure->getMessage());

        self::assertDirectoryDoesNotExist($this->root . '/src/Deep');
    }

    public function testVerifyRejectsTamperingAfterTheApply(): void
    {
        $this->gitInit();
        $this->apply($this->plan(), label: 'TAMPER');
        $path = 'src/Deep/New/Thing.php';
        $original = $this->read($path);

        foreach ([
            'edited content' => static fn (string $content): string => $content . "// more\n",
            'class renamed' => static fn (string $content): string => str_replace('class Thing', 'class Renamed', $content),
        ] as $case => $change) {
            $this->write($path, $change($original));
            (new IndexWriter())->write($this->map(), $this->root . '/map.json');
            $failure = $this->capturedOrFail(fn () => (new EditEngine())->verify($this->root, '.agent-edit/receipts/TAMPER', $this->root . '/map.json'), $case);
            self::assertStringContainsString('does not hold exactly the planned content', $failure->getMessage(), $case);
        }

        unlink($this->root . '/' . $path);
        (new IndexWriter())->write($this->map(), $this->root . '/map.json');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Scaffolded class source is missing after execution');
        (new EditEngine())->verify($this->root, '.agent-edit/receipts/TAMPER', $this->root . '/map.json');
    }

    public function testVerifyRejectsAnotherChangedFileInTheObservedScope(): void
    {
        $this->gitInit();
        $plan = $this->plan();
        $map = $this->map();
        (new IndexWriter())->write($map, $this->root . '/map.json');
        file_put_contents($this->root . '/plan.json', json_encode($plan, JSON_THROW_ON_ERROR));
        // The receipt claims one more changed file than the plan may touch.
        $this->apply($plan, label: 'SCOPE');
        $receiptPath = $this->root . '/.agent-edit/receipts/SCOPE/execution.json';
        $receipt = json_decode((string) file_get_contents($receiptPath), true, 512, JSON_THROW_ON_ERROR);
        $receipt['changed_files'][] = 'src/Existing.php';
        file_put_contents($receiptPath, json_encode($receipt, JSON_THROW_ON_ERROR));
        (new IndexWriter())->write($this->map(), $this->root . '/map.json');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('do not exactly match plan scope');
        (new EditEngine())->verify($this->root, '.agent-edit/receipts/SCOPE', $this->root . '/map.json');
    }

    public function testCapabilityIsListedWithoutRequiringPhpStan(): void
    {
        $capability = (new EditEngine())->capabilities()->find('class_scaffold_plan');

        self::assertNotNull($capability);
        self::assertSame('class-scaffold-plan', $capability->runner);
        self::assertFalse($capability->requiresPhpStan);
        self::assertEquals($capability, (new EditEngine())->capabilities()->findByRunner('class-scaffold-plan'));
    }

    private function lint(string $relative): int
    {
        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($this->root . '/' . $relative) . ' 2>&1', $output, $exit);

        return $exit;
    }
}
