<?php

declare(strict_types=1);

namespace voku\AgentEdit\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentEdit\EditEngine;
use voku\AgentEdit\Receipt\ApplyRequest;
use voku\AgentEdit\Tests\Support\CachedAgentMapBuilder;
use voku\AgentEdit\Tests\Support\TemporaryProject;
use voku\AgentMap\Index\IndexWriter;
use voku\AgentMap\Rename\MethodRenamePlanner;

/**
 * Regression: re-running `apply` into a bundle that already records an applied plan overwrote its receipt with a
 * `runner_failed` one while the old `passed` verification result stayed behind, so the real edit lost its evidence.
 */
final class ReceiptBundleReuseTest extends TestCase
{
    private TemporaryProject $project;
    private string $bundle;

    protected function setUp(): void
    {
        $this->project = new TemporaryProject([
            'src/Greeter.php' => "<?php\n\nnamespace App;\n\nfinal class Greeter\n{\n    public function greet(): string\n    {\n        return 'hi';\n    }\n\n    public function run(): string\n    {\n        return \$this->greet();\n    }\n}\n",
        ]);
        $root = escapeshellarg($this->project->root);
        exec("git -C {$root} init -q && git -C {$root} add -A && git -C {$root} -c user.email=t@t -c user.name=t commit -qm init");
        $this->bundle = $this->project->path('.agent-edit/receipts/plan');
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function testARetryNeverOverwritesAnAppliedReceiptOrItsVerdict(): void
    {
        $this->writePlan();
        self::assertTrue($this->apply(false)->succeeded());
        $this->rebuildMap();
        self::assertSame('passed', (new EditEngine())->verify($this->project->root, $this->bundle)['status']);
        $receipt = (string) file_get_contents($this->bundle . '/execution.json');
        $verdict = (string) file_get_contents($this->bundle . '/verification-result.json');

        foreach ([false, true] as $dryRun) {
            try {
                $this->apply($dryRun);
                self::fail('A bundle with an applied receipt must not be reused.');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('already records an applied plan', $exception->getMessage());
            }
        }

        self::assertSame($receipt, (string) file_get_contents($this->bundle . '/execution.json'));
        self::assertSame($verdict, (string) file_get_contents($this->bundle . '/verification-result.json'));
    }

    public function testADryRunBundleCanBeAppliedAndAStaleVerdictIsInvalidated(): void
    {
        $this->writePlan();
        self::assertSame('prepared', $this->apply(true)->status);
        // A verdict next to a non-applied receipt can only be stale.
        file_put_contents($this->bundle . '/verification-result.json', '{"status": "passed"}');

        self::assertTrue($this->apply(false)->succeeded());

        self::assertFileDoesNotExist($this->bundle . '/verification-result.json');
        self::assertSame('runner_succeeded', json_decode((string) file_get_contents($this->bundle . '/execution.json'), true)['status']);
    }

    public function testUndoDropsTheVerdictOfTheStateItRemoved(): void
    {
        file_put_contents($this->project->path('edit.json'), json_encode([
            'type' => 'source_edit_plan',
            'contract_version' => '1.0',
            'target_id' => 'fix greeting',
            'edits' => [['path' => 'src/Greeter.php', 'find' => "return 'hi';", 'replace' => "return 'hello';"]],
        ], JSON_THROW_ON_ERROR));
        $engine = new EditEngine();
        $engine->applyWithReceipt(new ApplyRequest(
            repositoryRoot: $this->project->root,
            planPath: $this->project->path('edit.json'),
            mapIndexPath: null,
            mapRoot: $this->project->root,
            outputDirectory: $this->bundle,
            label: 'plan',
            dryRun: false,
        ));
        self::assertSame('passed', $engine->verify($this->project->root, $this->bundle)['status']);

        $engine->undo($this->project->root, $this->bundle);

        self::assertFileDoesNotExist($this->bundle . '/verification-result.json', 'A passed verdict must not outlive the state it verified.');
        self::assertStringContainsString("return 'hi';", $this->project->read('src/Greeter.php'));
    }

    private function apply(bool $dryRun): \voku\AgentEdit\Receipt\EditReceipt
    {
        return (new EditEngine())->applyWithReceipt(new ApplyRequest(
            repositoryRoot: $this->project->root,
            planPath: $this->project->path('plan.json'),
            mapIndexPath: $this->project->path('.agent-map/php-symbols.json'),
            mapRoot: $this->project->root,
            outputDirectory: $this->bundle,
            label: 'plan',
            dryRun: $dryRun,
        ));
    }

    private function writePlan(): void
    {
        $plan = (new MethodRenamePlanner())->plan($this->rebuildMap(), 'App\Greeter::greet', 'hello')->toArray();
        self::assertSame('safe', $plan['status'] ?? null);
        file_put_contents($this->project->path('plan.json'), json_encode($plan, JSON_THROW_ON_ERROR));
    }

    private function rebuildMap(): \voku\AgentMap\Index\AgentMapIndex
    {
        $map = CachedAgentMapBuilder::build($this->project->root, ['src']);
        if (!is_dir($this->project->path('.agent-map'))) {
            mkdir($this->project->path('.agent-map'));
        }
        (new IndexWriter())->write($map, $this->project->path('.agent-map/php-symbols.json'));

        return $map;
    }
}
