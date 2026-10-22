<?php

declare(strict_types=1);

namespace voku\AgentEdit\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentEdit\Apply\MethodInsertionPlanApplier;
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
use voku\AgentMap\Scaffold\MethodCopyPlanner;
use voku\AgentMap\Scaffold\MethodScaffoldPlanner;

/**
 * method_copy_plan@1.0 and method_scaffold_plan@1.0 end to end with real agent-map plans, and the forged ones a hostile or
 * stale plan could be: complements MethodInsertionPlanConsumerTest, which pins the happy path and the Map-evidence refusals.
 */
final class MethodInsertionHardeningTest extends TestCase
{
    use CapturesFailures;
    use ScratchProject;

    private const DEST = "<?php\n\ndeclare(strict_types=1);\n\nnamespace Demo;\n\nfinal class Dest\n{\n    public function existing(): int\n    {\n        return 1;\n    }\n}\n";

    protected function setUp(): void
    {
        $this->createScratchProject('agent-edit-method-insertion-');
        $this->write('src/Dest.php', self::DEST);
        $this->write('src/Lib/Helper.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace Demo\\Lib;\n\nfinal class Helper\n{\n    public function name(): string\n    {\n        return 'h';\n    }\n}\n");
        $this->write('src/Lib/Other.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace Demo\\Lib;\n\nfinal class Other\n{\n}\n");
        $this->write('src/Src.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Demo;

use Demo\Lib\Helper;
use Demo\Lib\Other as Aliased;

final class Src
{
    public function plain(string $to): string
    {
        return 'Hello ' . $to;
    }

    /**
     * Needs an import the destination lacks.
     */
    public function needsImport(Helper $helper): string
    {
        return $helper->name() . 'x';
    }

    public function aliased(Aliased $other): Aliased
    {
        return $other;
    }

    public function withClosure(array $items): array
    {
        return array_map(function (string $item): string {
            return $item . '!';
        }, $items);
    }
}
PHP);
    }

    protected function tearDown(): void
    {
        $this->removeScratchProject();
    }

    private function map(): AgentMapIndex
    {
        return CachedAgentMapBuilder::build($this->root, ['src'], []);
    }

    /** @return array<string, mixed> */
    private function copyPlan(string $source = 'Demo\\Src::plain', string $destination = 'Demo\\Dest', ?string $name = null): array
    {
        return (new MethodCopyPlanner())->plan($this->map(), $source, $destination, $name)->toArray();
    }

    /** @return array<string, mixed> */
    private function scaffoldPlan(string $target = 'Demo\\Dest::tomorrow'): array
    {
        return (new MethodScaffoldPlanner())->plan($this->map(), $target, 'public', false, ['string $when'], 'string', 'Tomorrow.', "return \$when . ' tomorrow';")->toArray();
    }

    /** @param array<string, mixed> $plan */
    private function apply(array $plan, bool $dryRun = false, string $label = 'INS'): EditReceipt
    {
        $map = $this->map();
        (new IndexWriter())->write($map, $this->root . '/map.json');
        file_put_contents($this->root . '/plan.json', json_encode($plan, JSON_THROW_ON_ERROR));

        return (new EditEngine())->applyWithReceipt(new ApplyRequest($this->root, $this->root . '/plan.json', $this->root . '/map.json', $this->root, $this->root . '/.agent-edit/receipts/' . $label, $label, dryRun: $dryRun));
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
        self::assertSame(self::DEST, $this->read('src/Dest.php'), 'a refusal changes nothing');
        self::assertDirectoryDoesNotExist($this->root . '/.agent-edit', 'a refusal leaves no bundle');
    }

    private function gitInit(): void
    {
        $this->write('.gitignore', "map.json\nplan.json\n.agent-edit/\n.agent-map/\n");
        exec('cd ' . escapeshellarg($this->root) . ' && git init -q . && git add -A && git -c user.email=t@example.invalid -c user.name=t commit -qm init', $out, $exit);
        self::assertSame(0, $exit, implode("\n", $out));
    }

    /**
     * @param array<string, mixed> $plan
     * @param callable(array<string, mixed>): array<string, mixed> $change applied to the last edit, the declaration insertion
     * @return array<string, mixed>
     */
    private function withInsertion(array $plan, callable $change): array
    {
        $index = array_key_last($plan['edits']);
        $plan['edits'][$index] = $change($plan['edits'][$index]);

        return $plan;
    }

    public function testCopyAddsTheMethodVerbatimAndVerifiesAgainstTheRebuiltMap(): void
    {
        $this->gitInit();
        $plan = $this->copyPlan();
        self::assertSame('safe', $plan['status']);

        $dry = $this->apply($plan, dryRun: true, label: 'DRY');
        self::assertSame(self::DEST, $this->read('src/Dest.php'));
        self::assertSame([['path' => 'src/Dest.php', 'change' => 'modified', 'lines_added' => 5, 'lines_removed' => 0]], $dry->plannedChanges);

        $real = $this->apply($plan, label: 'COPY');
        self::assertSame(['src/Dest.php'], $real->changedFiles);
        $after = $this->read('src/Dest.php');
        self::assertStringContainsString("    public function plain(string \$to): string\n    {\n        return 'Hello ' . \$to;\n    }\n}\n", $after);
        self::assertSame($after, UnifiedPatch::apply(self::DEST, $dry->diff), 'the previewed diff is the real change');
        self::assertStringContainsString('public function plain', $this->read('src/Src.php'), 'a copy leaves the source method in place');
        self::assertSame(0, $this->lint('src/Dest.php'));

        (new IndexWriter())->write($this->map(), $this->root . '/map.json');
        $result = (new EditEngine())->verify($this->root, '.agent-edit/receipts/COPY', $this->root . '/map.json');

        self::assertSame('passed', $result['status']);
        self::assertSame('method_copy_plan_verification', $result['kind']);
        self::assertSame(['src/Dest.php'], $result['changed_files']);
    }

    public function testCopyCarriesThePlainImportTheDestinationLacksAsAZeroLengthInsertion(): void
    {
        $this->gitInit();
        $plan = $this->copyPlan('Demo\\Src::needsImport');
        $roles = array_column($plan['edits'], 'role');
        self::assertSame(['use_statement_insertion', 'method_declaration_insertion'], $roles, 'the planner adds the import as its own edit');
        self::assertSame('', $plan['edits'][0]['expected']);

        $dry = $this->apply($plan, dryRun: true, label: 'DRY');
        $this->apply($plan, label: 'IMPORT');

        $after = $this->read('src/Dest.php');
        self::assertSame(1, substr_count($after, 'use Demo\\Lib\\Helper;'));
        self::assertLessThan(strpos($after, 'final class Dest'), strpos($after, 'use Demo\\Lib\\Helper;'), 'the import lands above the class');
        self::assertSame($after, UnifiedPatch::apply(self::DEST, $dry->diff));
        self::assertSame(0, $this->lint('src/Dest.php'));

        (new IndexWriter())->write($this->map(), $this->root . '/map.json');
        self::assertSame('passed', (new EditEngine())->verify($this->root, '.agent-edit/receipts/IMPORT', $this->root . '/map.json')['status']);
    }

    public function testCopyOfAMethodWithAClosureInItsBodyIsNotMistakenForASecondMethod(): void
    {
        $this->apply($this->copyPlan('Demo\\Src::withClosure'), label: 'CLOSURE');

        self::assertStringContainsString('function withClosure', $this->read('src/Dest.php'));
        self::assertSame(0, $this->lint('src/Dest.php'));
    }

    public function testCopyUnderANewNameIsBoundToThatName(): void
    {
        $this->apply($this->copyPlan('Demo\\Src::plain', 'Demo\\Dest', 'greet'), label: 'RENAMED');

        self::assertStringContainsString('function greet(string $to)', $this->read('src/Dest.php'));
        self::assertStringNotContainsString('function plain', $this->read('src/Dest.php'));
    }

    public function testScaffoldInsertsTheGeneratedMethodAndVerifies(): void
    {
        $this->gitInit();
        $plan = $this->scaffoldPlan();
        self::assertSame('safe', $plan['status']);

        $this->apply($plan, label: 'SCAFFOLD');

        $after = $this->read('src/Dest.php');
        self::assertStringContainsString("public function tomorrow(string \$when): string\n    {\n        return \$when . ' tomorrow';\n    }", $after);
        self::assertSame(0, $this->lint('src/Dest.php'));

        (new IndexWriter())->write($this->map(), $this->root . '/map.json');
        $result = (new EditEngine())->verify($this->root, '.agent-edit/receipts/SCAFFOLD', $this->root . '/map.json');
        self::assertSame('passed', $result['status']);
        self::assertSame('method_scaffold_plan_verification', $result['kind']);
    }

    public function testVerifyFailsWhenTheTextIsThereButTheClassDoesNotDeclareTheMethod(): void
    {
        $this->gitInit();
        $this->apply($this->copyPlan(), label: 'RENAMED-CLASS');
        // Every byte the plan inserted is still in the file and the rebuilt Map matches it, but the class was renamed away.
        $this->write('src/Dest.php', str_replace('final class Dest', 'final class DestRenamed', $this->read('src/Dest.php')));
        (new IndexWriter())->write($this->map(), $this->root . '/map.json');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Inserted method is missing from the rebuilt Map: Demo\\Dest::plain');
        (new EditEngine())->verify($this->root, '.agent-edit/receipts/RENAMED-CLASS', $this->root . '/map.json');
    }

    public function testAnEditMadeAfterTheReceiptIsNotAttributedToThePlan(): void
    {
        $this->gitInit();
        $this->apply($this->scaffoldPlan(), label: 'EXTRA');
        $this->write('src/Lib/Other.php', $this->read('src/Lib/Other.php') . "\n// drive-by edit\n");
        (new IndexWriter())->write($this->map(), $this->root . '/map.json');

        // The receipt was observed before the drive-by edit, so the plan scope still matches; the Map hash check must hold.
        $result = (new EditEngine())->verify($this->root, '.agent-edit/receipts/EXTRA', $this->root . '/map.json');
        self::assertSame('passed', $result['status']);
        self::assertSame(['src/Dest.php'], $result['changed_files']);
    }

    public function testBlockedAndReviewRequiredPlansAreRefused(): void
    {
        foreach (['blocked', 'review_required'] as $status) {
            $plan = $this->scaffoldPlan();
            $plan['status'] = $status;
            $plan['blockers'] = ['because ' . $status];
            $this->assertRefused($plan, 'Method scaffold plan', FailureCode::PlanRefused);
        }
    }

    public function testACopyWithOwnerDependenciesNeedsReview(): void
    {
        $plan = $this->copyPlan();
        $plan['owner_dependencies'] = [['kind' => 'this', 'line' => 3]];

        $this->assertRefused($plan, 'requires empty owner_dependencies evidence', FailureCode::PlanRefused);
    }

    public function testACopyPlanThatNamesAnAliasedImportIsRefusedBecauseTheAliasWouldBeLost(): void
    {
        $plan = $this->copyPlan('Demo\\Src::aliased');
        self::assertSame('safe', $plan['status'], 'agent-map itself still reports this plan as safe');

        try {
            $this->apply($plan, dryRun: true, label: 'ALIAS');
            self::fail('Expected the aliased import to be refused.');
        } catch (CodedFailure $failure) {
            self::assertSame(FailureCode::PlanRefused, $failure->failureCode());
            self::assertSame('Aliased', $failure->details()['alias']);
            self::assertSame('Demo\\Lib\\Other', $failure->details()['import']);
            self::assertStringContainsString('would name an undefined class', $failure->getMessage());
        }
        self::assertSame(self::DEST, $this->read('src/Dest.php'));
    }

    public function testACopyThatDoesNotUseTheSourceFilesAliasIsNotRefused(): void
    {
        $this->apply($this->copyPlan('Demo\\Src::plain'), label: 'NOALIAS');

        self::assertStringContainsString('function plain', $this->read('src/Dest.php'));
    }

    /** @dataProvider forgedInsertions */
    public function testForgedInsertionsAreRefusedBeforeAnyChange(callable $forge, string $message): void
    {
        $this->assertRefused($forge($this->scaffoldPlan()), $message);
    }

    /** @return iterable<string, array{callable(array<string, mixed>): array<string, mixed>, string}> */
    public static function forgedInsertions(): iterable
    {
        $edit = static fn (callable $change): callable => static function (array $plan) use ($change): array {
            $plan['edits'][0] = $change($plan['edits'][0]);

            return $plan;
        };
        yield 'wrong role' => [$edit(static fn (array $e): array => ['role' => 'method_declaration_removal'] + $e), 'Unsupported method_scaffold_plan edit role'];
        yield 'bound to another symbol' => [$edit(static fn (array $e): array => ['symbol_id' => 'Demo\\Other::tomorrow'] + $e), 'not bound to its exact destination method'];
        yield 'wrong resolution' => [$edit(static fn (array $e): array => ['resolution' => 'phpstan_resolved'] + $e), 'not bound to its exact destination method'];
        yield 'replaces more than the closing brace' => [$edit(static fn (array $e): array => ['expected' => '}}'] + $e), 'closing brace'];
        yield 'replaces a different byte' => [$edit(static fn (array $e): array => ['expected' => ';'] + $e), 'closing brace'];
        yield 'range spans bytes' => [$edit(static fn (array $e): array => ['end_file_pos' => $e['end_file_pos'] + 3] + $e), 'closing brace'];
        yield 'drops the closing brace' => [$edit(static fn (array $e): array => ['replacement' => rtrim($e['replacement'], '}')] + $e), 'closing brace'];
        yield 'declares another method name' => [$edit(static fn (array $e): array => ['replacement' => str_replace('function tomorrow', 'function yesterday', $e['replacement'])] + $e), 'exactly the named method'];
        yield 'declares two methods' => [$edit(static fn (array $e): array => ['replacement' => str_replace("\n}", "\n    public function more(): void\n    {\n    }\n}", $e['replacement'])] + $e), 'exactly the named method'];
        yield 'adds a property' => [$edit(static fn (array $e): array => ['replacement' => str_replace("\n}", "\n    private int \$x = 1;\n}", $e['replacement'])] + $e), 'no other class member'];
        yield 'adds a constant' => [$edit(static fn (array $e): array => ['replacement' => str_replace("\n}", "\n    public const X = 1;\n}", $e['replacement'])] + $e), 'no other class member'];
        yield 'adds a trait use' => [$edit(static fn (array $e): array => ['replacement' => str_replace("\n}", "\n    use SomeTrait;\n}", $e['replacement'])] + $e), 'no other class member'];
        yield 'does not parse' => [$edit(static fn (array $e): array => ['replacement' => "\n    public function tomorrow( {\n}"] + $e), 'does not parse as class members'];
        yield 'second file' => [static function (array $plan): array {
            $plan['edits'][] = ['path' => 'src/Src.php'] + $plan['edits'][0];

            return $plan;
        }, 'must all target the destination class file'];
        yield 'two insertions' => [static function (array $plan): array {
            $plan['edits'][] = $plan['edits'][0];

            return $plan;
        }, 'exactly one method declaration insertion'];
        yield 'no insertion' => [static function (array $plan): array {
            $plan['edits'] = [];

            return $plan;
        }, 'requires exact insertion edits'];
        yield 'carries a move' => [static function (array $plan): array {
            $plan['moves'] = [['from_path' => 'a', 'to_path' => 'b']];

            return $plan;
        }, 'may not publish file moves'];
        yield 'target does not name the new method' => [static function (array $plan): array {
            $plan['target_id'] = 'method:Demo\\Dest::other';

            return $plan;
        }, 'target identity is malformed'];
        yield 'invalid method name' => [static function (array $plan): array {
            $plan['method_name'] = 'bad name';

            return $plan;
        }, 'target identity is malformed'];
        yield 'leading backslash destination' => [static function (array $plan): array {
            $plan['target_class'] = '\\Demo\\Dest';

            return $plan;
        }, 'target identity is malformed'];
        yield 'unsupported contract version' => [static function (array $plan): array {
            $plan['contract_version'] = '2.0';

            return $plan;
        }, 'Unsupported plan type or contract version'];
    }

    public function testAnImportEditMustBeOneTrustworthyUseStatement(): void
    {
        $plan = $this->copyPlan('Demo\\Src::needsImport');

        foreach ([
            'multiple statements' => "use Demo\\Lib\\Helper;\nuse Evil\\Thing;\n",
            'code smuggled in' => "use Demo\\Lib\\Helper; system('x');\n",
            'aliased' => "use Demo\\Lib\\Helper as H;\n",
            'no trailing newline' => 'use Demo\\Lib\\Helper;',
        ] as $case => $replacement) {
            $forged = $plan;
            $forged['edits'][0]['replacement'] = $replacement;
            $this->assertRefusedWith($forged, $case);
        }

        $mismatch = $plan;
        $mismatch['edits'][0]['symbol_id'] = 'use:Demo\\Lib\\Other';
        $this->assertRefusedWith($mismatch, 'symbol mismatch');
    }

    public function testAUseEditMayNeverReplaceExistingBytes(): void
    {
        $plan = $this->copyPlan('Demo\\Src::needsImport');
        $needle = 'declare(strict_types=1);';
        $start = (int) strpos(self::DEST, $needle);
        // Forged: a well-formed `use` edit that would delete the strict_types declaration instead of inserting before it.
        $plan['edits'][0]['start_file_pos'] = $start;
        $plan['edits'][0]['end_file_pos'] = $start + strlen($needle) - 1;
        $plan['edits'][0]['expected'] = $needle;

        $this->assertRefusedWith($plan, 'dependency insertion has unexpected evidence');
    }

    /** @param array<string, mixed> $plan */
    private function assertRefusedWith(array $plan, string $case): void
    {
        $failure = $this->capturedOrFail(fn () => $this->apply($plan, dryRun: true, label: 'FORGED'), $case);

        self::assertMatchesRegularExpression('/use import edit|dependency insertion has unexpected evidence/', $failure->getMessage(), $case);
        self::assertSame(self::DEST, $this->read('src/Dest.php'), $case);
    }

    public function testTheDestinationMustStillMatchTheMapAtApplyTime(): void
    {
        $plan = $this->scaffoldPlan();

        $moved = $this->withInsertion($plan, static fn (array $e): array => ['line_start' => $e['line_start'] + 2, 'line_end' => $e['line_end'] + 2] + $e);
        $this->assertRefused($moved, 'not at the closing brace of Demo\\Dest', FailureCode::EvidenceChanged);

        $missing = $plan;
        $missing['target_class'] = 'Demo\\Nowhere';
        $missing['target_id'] = 'method:Demo\\Nowhere::tomorrow';
        $missing['edits'][0]['symbol_id'] = 'Demo\\Nowhere::tomorrow';
        $this->assertRefused($missing, 'Destination class Demo\\Nowhere is not declared', FailureCode::EvidenceChanged);
    }

    public function testADestinationThatAlreadyDeclaresTheMethodIsNeverGivenASecond(): void
    {
        $forged = $this->scaffoldPlan('Demo\\Dest::existing');
        // The planner blocks this; a forged "safe" version must still be refused by the host.
        $forged['status'] = 'safe';
        $forged['blockers'] = [];
        $forged['edits'] ??= [];
        if ($forged['edits'] === []) {
            $good = $this->scaffoldPlan();
            $forged = $good;
            $forged['target_id'] = 'method:Demo\\Dest::existing';
            $forged['method_name'] = 'existing';
            $forged['edits'][0]['symbol_id'] = 'Demo\\Dest::existing';
            $forged['edits'][0]['replacement'] = str_replace('function tomorrow', 'function existing', $forged['edits'][0]['replacement']);
        }

        $this->assertRefused($forged, 'already declares existing()', FailureCode::EvidenceChanged);
    }

    public function testASourceEditedAfterPlanningIsStale(): void
    {
        $plan = $this->scaffoldPlan();
        $map = $this->map();
        (new IndexWriter())->write($map, $this->root . '/map.json');
        $this->write('src/Dest.php', self::DEST . "\n// edited after planning\n");
        file_put_contents($this->root . '/plan.json', json_encode($plan, JSON_THROW_ON_ERROR));

        try {
            (new EditEngine())->applyWithReceipt(new ApplyRequest($this->root, $this->root . '/plan.json', $this->root . '/map.json', $this->root, $this->root . '/.agent-edit/receipts/STALE', 'STALE'));
            self::fail('Expected a stale refusal.');
        } catch (CodedFailure $failure) {
            self::assertSame(FailureCode::EvidenceStale, $failure->failureCode());
        }
        self::assertStringNotContainsString('tomorrow', $this->read('src/Dest.php'));
    }

    public function testTwoInsertionsAtTheSameOffsetAreRefusedAsAmbiguous(): void
    {
        $plan = $this->copyPlan('Demo\\Src::needsImport');
        $plan['edits'][] = $plan['edits'][0];

        $failure = $this->capturedOrFail(fn () => (new MethodInsertionPlanApplier())->preflight($plan, $this->map(), $this->root), 'two insertions at one offset');

        self::assertStringContainsString('overlapping edit ranges', $failure->getMessage());
    }

    public function testAnInsertionAtTheVeryEndOfTheFileIsLegalButOnePastItIsNot(): void
    {
        $plan = $this->copyPlan('Demo\\Src::needsImport');
        $size = strlen(self::DEST);
        $atEnd = $plan;
        $atEnd['edits'][0]['start_file_pos'] = $atEnd['edits'][0]['end_file_pos'] = $size;
        $past = $plan;
        $past['edits'][0]['start_file_pos'] = $past['edits'][0]['end_file_pos'] = $size + 1;

        // One past the end names no offset in the file at all; it must be refused as changed evidence, not read as ''.
        $failure = $this->capturedOrFail(fn () => (new MethodInsertionPlanApplier())->preflight($past, $this->map(), $this->root), 'one byte past the end');
        self::assertStringContainsString('evidence changed before apply', $failure->getMessage());

        $prepared = (new MethodInsertionPlanApplier())->preflight($atEnd, $this->map(), $this->root);
        self::assertStringEndsWith("\nuse Demo\\Lib\\Helper;\n", $prepared['files'][$this->root . '/src/Dest.php']);
    }

    public function testCapabilitiesListBothAsExecutableWithTheirRunners(): void
    {
        $capabilities = (new EditEngine())->capabilities();

        foreach (['method_copy_plan' => 'method-copy-plan', 'method_scaffold_plan' => 'method-scaffold-plan'] as $type => $runner) {
            $capability = $capabilities->find($type);
            self::assertNotNull($capability, $type);
            self::assertSame($runner, $capability->runner);
            self::assertTrue($capability->requiresPhpStan);
            self::assertEquals($capability, $capabilities->findByRunner($runner));
        }
    }

    private function lint(string $relative): int
    {
        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($this->root . '/' . $relative) . ' 2>&1', $output, $exit);

        return $exit;
    }
}
