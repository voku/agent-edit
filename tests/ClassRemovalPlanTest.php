<?php

declare(strict_types=1);

namespace voku\AgentEdit\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentEdit\Apply\ClassRemovalPlanApplier;
use voku\AgentEdit\EditEngine;
use voku\AgentEdit\Receipt\ApplyRequest;
use voku\AgentEdit\Tests\Support\CachedAgentMapBuilder;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\IndexWriter;
use voku\AgentMap\Removal\ClassRemovalPlanner;

/** class_removal_plan@1.0 end to end: a real agent-map plan, dry-run, apply, rebuilt Map, verify. */
final class ClassRemovalPlanTest extends TestCase
{
    private string $root;
    private AgentMapIndex $map;
    private string $mapPath;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-edit-class-removal-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0o775, true);
        file_put_contents($this->root . '/src/Legacy.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace Demo;\n\nfinal class Legacy\n{\n    public function run(): void\n    {\n    }\n}\n");
        file_put_contents($this->root . '/src/Keep.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace Demo;\n\nfinal class Keep\n{\n}\n");
        $this->map = CachedAgentMapBuilder::build($this->root, ['src'], []);
        $this->mapPath = $this->root . '/map.json';
        (new IndexWriter())->write($this->map, $this->mapPath);
    }

    protected function tearDown(): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->root);
    }

    public function testPlanDryRunApplyRebuildMapVerify(): void
    {
        $plan = $this->plan();
        self::assertSame('safe', $plan['status']);
        $this->writePlan($plan);
        $engine = new EditEngine();

        $dry = $engine->applyWithReceipt($this->request(dryRun: true, output: '.agent-edit/receipts/DRY'));
        self::assertSame('prepared', $dry->status);
        self::assertFileExists($this->root . '/src/Legacy.php');

        $engine->applyWithReceipt($this->request(dryRun: false, output: '.agent-edit/receipts/REMOVE'));
        self::assertFileDoesNotExist($this->root . '/src/Legacy.php');
        self::assertFileExists($this->root . '/src/Keep.php');
        self::assertSame([], glob($this->root . '/src/*.agent-edit-plan-*'));
        $receipt = json_decode((string) file_get_contents($this->root . '/.agent-edit/receipts/REMOVE/execution.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('class-removal-plan', $receipt['runner']['name']);
        self::assertSame(['src/Legacy.php'], $receipt['changed_files']);

        (new IndexWriter())->write(CachedAgentMapBuilder::build($this->root, ['src'], []), $this->mapPath);
        $result = $engine->verify($this->root, '.agent-edit/receipts/REMOVE', $this->mapPath);

        self::assertSame('class_removal_plan_verification', $result['kind']);
        self::assertSame('incomplete', $result['status'], 'Git-free evidence is scope_unproven, never passed.');
        self::assertSame(['src/Legacy.php'], $result['changed_files']);
        self::assertSame('passed', $result['checks']['target_absent']);
        self::assertSame('passed', $result['checks']['source_absent']);
    }

    public function testVerifyFailsWhenTheClassSourceIsRestored(): void
    {
        $plan = $this->plan();
        $this->writePlan($plan);
        $source = (string) file_get_contents($this->root . '/src/Legacy.php');
        $engine = new EditEngine();
        $engine->applyWithReceipt($this->request(dryRun: false, output: '.agent-edit/receipts/REMOVE'));
        file_put_contents($this->root . '/src/Legacy.php', $source);
        (new IndexWriter())->write(CachedAgentMapBuilder::build($this->root, ['src'], []), $this->mapPath);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('do not exactly match plan scope');
        $engine->verify($this->root, '.agent-edit/receipts/REMOVE', $this->mapPath);
    }

    public function testReviewRequiredAndBlockedPlansAreRefusedBeforeAnyChange(): void
    {
        foreach (['review_required', 'blocked'] as $status) {
            $plan = $this->plan();
            $plan['status'] = $status;
            try {
                (new ClassRemovalPlanApplier())->preflight($plan, $this->map, $this->root);
                self::fail('Expected refusal for ' . $status);
            } catch (RuntimeException $refusal) {
                self::assertStringContainsString('not safe', $refusal->getMessage());
            }
            self::assertFileExists($this->root . '/src/Legacy.php');
        }
    }

    /** @dataProvider malformedPlans */
    public function testMalformedPlansFailClosed(callable $mutate, string $message): void
    {
        $plan = $mutate($this->plan());

        try {
            (new ClassRemovalPlanApplier())->apply($plan, $this->map, $this->root);
            self::fail('Expected a fail-closed refusal.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString($message, $exception->getMessage());
        }
        self::assertFileExists($this->root . '/src/Legacy.php');
        self::assertSame([], glob($this->root . '/src/*.agent-edit-plan-*'));
    }

    /** @return iterable<string, array{callable(array<string, mixed>): array<string, mixed>, string}> */
    public static function malformedPlans(): iterable
    {
        yield 'carries edits' => [static function (array $plan): array {
            $plan['edits'] = [['path' => 'src/Keep.php']];

            return $plan;
        }, 'cannot publish source edits'];
        yield 'carries moves' => [static function (array $plan): array {
            $plan['moves'] = [['from_path' => 'a', 'to_path' => 'b']];

            return $plan;
        }, 'cannot publish file moves'];
        yield 'two deletions' => [static function (array $plan): array {
            $plan['deletions'][] = ['path' => 'src/Keep.php', 'source_sha256' => 'sha256:' . str_repeat('1', 64), 'reason' => 'x'];

            return $plan;
        }, 'exactly one whole-file deletion'];
        yield 'no deletion' => [static function (array $plan): array {
            $plan['deletions'] = [];

            return $plan;
        }, 'exactly one whole-file deletion'];
        yield 'stale hash' => [static function (array $plan): array {
            $plan['deletions'][0]['source_sha256'] = 'sha256:' . str_repeat('0', 64);

            return $plan;
        }, 'evidence changed before apply'];
        yield 'blind spots' => [static function (array $plan): array {
            $plan['blind_spots'] = [['kind' => 'class_attributes']];

            return $plan;
        }, 'requires explicit review'];
        yield 'blockers' => [static function (array $plan): array {
            $plan['blockers'] = ['still referenced'];

            return $plan;
        }, 'semantic blockers'];
        yield 'wrong target kind' => [static function (array $plan): array {
            $plan['target_id'] = 'method:Demo\\Legacy::run';

            return $plan;
        }, 'must be a class'];
        yield 'traversal path' => [static function (array $plan): array {
            $plan['deletions'][0]['path'] = '../etc/passwd';

            return $plan;
        }, 'Class removal'];
        yield 'target not sole declaration' => [static function (array $plan): array {
            $plan['target_id'] = 'class:Demo\\Keep';

            return $plan;
        }, 'declare only the target class'];
    }

    public function testChangedSourceAfterPlanningIsRefusedAndUntouched(): void
    {
        $plan = $this->plan();
        $changed = (string) file_get_contents($this->root . '/src/Legacy.php') . "\n// edited after planning\n";
        file_put_contents($this->root . '/src/Legacy.php', $changed);

        try {
            (new ClassRemovalPlanApplier())->apply($plan, $this->map, $this->root);
            self::fail('Expected refusal.');
        } catch (RuntimeException $exception) {
            self::assertMatchesRegularExpression('/stale|evidence changed before apply/', $exception->getMessage());
        }
        self::assertSame($changed, file_get_contents($this->root . '/src/Legacy.php'));
    }

    public function testStillReferencedClassIsRefusedByThePlannerAndNeverExecutable(): void
    {
        file_put_contents($this->root . '/src/Keep.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace Demo;\n\nfinal class Keep\n{\n    public function use(Legacy \$legacy): void\n    {\n    }\n}\n");
        $map = CachedAgentMapBuilder::build($this->root, ['src'], []);
        $plan = (new ClassRemovalPlanner())->plan($map, 'Demo\\Legacy')->toArray();

        self::assertSame('blocked', $plan['status']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not safe');
        (new ClassRemovalPlanApplier())->apply($plan, $map, $this->root);
    }

    public function testRenameFailureRestoresTheOriginalSource(): void
    {
        $plan = $this->plan();
        $before = (string) file_get_contents($this->root . '/src/Legacy.php');
        $calls = 0;
        $applier = new ClassRemovalPlanApplier(static function (string $from, string $to) use (&$calls): bool {
            ++$calls;
            $moved = rename($from, $to);

            // The first move (source -> backup) happens but is reported as failed; the applier must still restore it.
            return $calls === 1 ? false : $moved;
        });

        try {
            $applier->apply($plan, $this->map, $this->root);
            self::fail('Expected failure.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('source file was restored', $exception->getMessage());
        }
        self::assertSame($before, file_get_contents($this->root . '/src/Legacy.php'));
        self::assertSame([], glob($this->root . '/src/*.agent-edit-plan-*'));
    }

    /** @return array<string, mixed> */
    private function plan(): array
    {
        $plan = (new ClassRemovalPlanner())->plan($this->map, 'Demo\\Legacy')->toArray();
        self::assertSame('class:Demo\\Legacy', $plan['target_id']);

        return $plan;
    }

    /** @param array<string, mixed> $plan */
    private function writePlan(array $plan): void
    {
        file_put_contents($this->root . '/plan.json', json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function request(bool $dryRun, string $output): ApplyRequest
    {
        return new ApplyRequest(
            repositoryRoot: $this->root,
            planPath: $this->root . '/plan.json',
            mapIndexPath: $this->mapPath,
            mapRoot: $this->root,
            outputDirectory: $this->root . '/' . $output,
            label: basename($output),
            dryRun: $dryRun,
        );
    }
}
