<?php

declare(strict_types=1);

namespace voku\AgentEdit\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentEdit\Apply\MethodInsertionPlanApplier;
use voku\AgentEdit\EditEngine;
use voku\AgentEdit\Receipt\ApplyRequest;
use voku\AgentEdit\Tests\Support\CachedAgentMapBuilder;
use voku\AgentMap\Index\IndexWriter;
use voku\AgentMap\Scaffold\MethodCopyPlanner;
use voku\AgentMap\Scaffold\MethodScaffoldPlanner;

final class MethodInsertionPlanConsumerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-edit-method-insertion-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0o775, true);
        file_put_contents($this->root . '/src/Source.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Fixture;

final class Source
{
    public static function normalize(string $value): string
    {
        return trim($value);
    }
}
PHP);
        file_put_contents($this->root . '/src/Destination.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Fixture;

final class Destination
{
}
PHP);
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

    public function testSafeMethodCopyAppliesThroughTheSharedTransaction(): void
    {
        [$map, $plan] = $this->copyPlan();
        $prepared = (new MethodInsertionPlanApplier())->preflight($plan, $map, $this->root);

        self::assertSame('method_copy_plan', $prepared['plan_type']);
        self::assertSame(1, $prepared['edit_count']);
        self::assertTrue((new MethodInsertionPlanApplier())->apply($plan, $map, $this->root)->succeeded());
        self::assertStringContainsString('function normalizeCopy(string $value): string', (string) file_get_contents($this->root . '/src/Destination.php'));
        self::assertStringContainsString('function normalize(string $value): string', (string) file_get_contents($this->root . '/src/Source.php'));
    }

    public function testSafeMethodScaffoldAppliesThroughTheSharedTransaction(): void
    {
        $map = CachedAgentMapBuilder::build($this->root, ['src'], []);
        $plan = (new MethodScaffoldPlanner($this->root))->plan(
            map: $map,
            target: 'Fixture\\Destination::parse',
            parameters: ['string $value'],
            returnType: 'string',
            docSummary: 'Parse the value.',
        )->toArray();

        self::assertSame('safe', $plan['status']);
        self::assertTrue((new MethodInsertionPlanApplier())->apply($plan, $map, $this->root)->succeeded());
        $destination = (string) file_get_contents($this->root . '/src/Destination.php');
        self::assertStringContainsString('function parse(string $value): string', $destination);
        self::assertStringContainsString('* Parse the value.', $destination);
    }

    public function testCopyWithOwnerDependenciesRemainsARefusalUntilReviewed(): void
    {
        file_put_contents($this->root . '/src/Stateful.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Fixture;

final class Stateful
{
    private string $prefix = 'prefix:';

    public function format(string $value): string
    {
        return $this->prefix . $value;
    }
}
PHP);
        $map = CachedAgentMapBuilder::build($this->root, ['src'], []);
        $plan = (new MethodCopyPlanner($this->root))->plan($map, 'Fixture\\Stateful::format', 'Fixture\\Destination')->toArray();
        $before = (string) file_get_contents($this->root . '/src/Destination.php');

        self::assertSame('review_required', $plan['status']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('requires explicit review');
        try {
            (new MethodInsertionPlanApplier())->preflight($plan, $map, $this->root);
        } finally {
            self::assertSame($before, (string) file_get_contents($this->root . '/src/Destination.php'));
        }
    }

    public function testMethodInsertionRejectsADeclarationWhoseEvidenceDoesNotMatchItsTarget(): void
    {
        [$map, $plan] = $this->copyPlan();
        $before = $this->sources();
        $plan['edits'][0]['symbol_id'] = 'Fixture\\Destination::different';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('exact destination method');
        try {
            (new MethodInsertionPlanApplier())->preflight($plan, $map, $this->root);
        } finally {
            self::assertSame($before, $this->sources());
        }
    }

    public function testMethodInsertionRequiresAPhpStanBackedMapBeforeMutation(): void
    {
        [$map, $plan] = $this->copyPlan();
        $structural = new \voku\AgentMap\Index\AgentMapIndex(
            schemaVersion: $map->schemaVersion,
            root: $map->root,
            backend: 'simple-php-code-parser+structural-only',
            files: $map->files,
            relations: $map->relations,
            diagnostics: $map->diagnostics,
            fingerprint: $map->fingerprint,
        );
        $before = $this->sources();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('requires a PHPStan-backed current map');
        try {
            (new MethodInsertionPlanApplier())->preflight($plan, $structural, $this->root);
        } finally {
            self::assertSame($before, $this->sources());
        }
    }

    public function testMethodCopyReceiptVerifiesTheExactAppliedCandidate(): void
    {
        [$map, $plan] = $this->copyPlan();
        $mapPath = $this->root . '/map.json';
        $planPath = $this->root . '/plan.json';
        $bundle = $this->root . '/.agent-edit/receipts/COPY';
        (new IndexWriter())->write($map, $mapPath);
        file_put_contents($planPath, json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $this->git('init -q .');
        $this->git('config user.email test@example.invalid');
        $this->git('config user.name test');
        $this->git('add src map.json plan.json');
        $this->git('commit -qm initial');

        $engine = new EditEngine();
        $receipt = $engine->applyWithReceipt(new ApplyRequest(
            repositoryRoot: $this->root,
            planPath: $planPath,
            mapIndexPath: $mapPath,
            mapRoot: $this->root,
            outputDirectory: $bundle,
            label: 'COPY',
        ));
        self::assertTrue($receipt->succeeded());

        (new IndexWriter())->write(CachedAgentMapBuilder::build($this->root, ['src'], []), $mapPath);
        $result = $engine->verify($this->root, $bundle, $mapPath);
        self::assertSame('passed', $result['status']);
        self::assertSame('method_copy_plan_verification', $result['kind']);
        self::assertSame(['src/Destination.php'], $result['changed_files']);
    }

    /** @return array{\voku\AgentMap\Index\AgentMapIndex, array<string, mixed>} */
    private function copyPlan(): array
    {
        $map = CachedAgentMapBuilder::build($this->root, ['src'], []);
        self::assertStringEndsWith('+phpstan', $map->backend);
        $plan = (new MethodCopyPlanner($this->root))->plan(
            map: $map,
            sourceTarget: 'Fixture\\Source::normalize',
            destinationClass: 'Fixture\\Destination',
            newMethodName: 'normalizeCopy',
        )->toArray();
        self::assertSame('safe', $plan['status'], implode("\n", $plan['blockers']));

        return [$map, $plan];
    }

    /** @return array<string, string> */
    private function sources(): array
    {
        return [
            'source' => (string) file_get_contents($this->root . '/src/Source.php'),
            'destination' => (string) file_get_contents($this->root . '/src/Destination.php'),
        ];
    }

    private function git(string $command): void
    {
        $output = [];
        exec('cd ' . escapeshellarg($this->root) . ' && git ' . $command . ' 2>&1', $output, $exitCode);
        self::assertSame(0, $exitCode, implode("\n", $output));
    }
}
