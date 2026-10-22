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
 * Regression: renaming `greet` to `name` must not be verified by the `name` inside `$name`. Reverting the renamed call
 * site left the code calling an undefined method while verify still reported `passed`.
 */
final class RenameVerifyWholeIdentifierTest extends TestCase
{
    private TemporaryProject $project;

    protected function setUp(): void
    {
        $this->project = new TemporaryProject([
            'src/Greeter.php' => <<<'PHP'
<?php

declare(strict_types=1);

namespace App;

final class Greeter
{
    public function greet(string $name): string
    {
        return 'Hello ' . $name;
    }

    public function run(): string
    {
        return $this->greet('x');
    }
}

PHP,
        ]);
        $root = escapeshellarg($this->project->root);
        exec("git -C {$root} init -q && git -C {$root} add -A && git -C {$root} -c user.email=t@t -c user.name=t commit -qm init");
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function testARevertedCallSiteFailsVerifyAlthoughTheNewNameOccursInsideAVariable(): void
    {
        $bundle = $this->applyRename();
        $this->project->write('src/Greeter.php', str_replace("\$this->name('x')", "\$this->greet('x')", $this->project->read('src/Greeter.php')));
        $this->rebuildMap();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Rewritten (token is missing|source contains fewer name occurrences)/');

        (new EditEngine())->verify($this->project->root, $bundle, '.agent-map/php-symbols.json');
    }

    public function testTheCompleteRenameStillPasses(): void
    {
        $bundle = $this->applyRename();
        $this->rebuildMap();

        self::assertSame('passed', (new EditEngine())->verify($this->project->root, $bundle, '.agent-map/php-symbols.json')['status']);
    }

    private function applyRename(): string
    {
        $map = $this->rebuildMap();
        $plan = (new MethodRenamePlanner())->plan($map, 'App\Greeter::greet', 'name')->toArray();
        self::assertSame('safe', $plan['status'] ?? null, json_encode($plan) ?: '');
        $planPath = $this->project->path('plan.json');
        file_put_contents($planPath, json_encode($plan, JSON_THROW_ON_ERROR));
        $bundle = $this->project->path('.agent-edit/receipts/rename');

        $receipt = (new EditEngine())->applyWithReceipt(new ApplyRequest(
            repositoryRoot: $this->project->root,
            planPath: $planPath,
            mapIndexPath: $this->project->path('.agent-map/php-symbols.json'),
            mapRoot: $this->project->root,
            outputDirectory: $bundle,
            label: 'rename',
            dryRun: false,
        ));
        self::assertTrue($receipt->succeeded());
        self::assertStringContainsString("\$this->name('x')", $this->project->read('src/Greeter.php'));

        return $bundle;
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
