<?php

declare(strict_types=1);

namespace voku\AgentEdit\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentEdit\Apply\MethodCopyPlanApplier;
use voku\AgentEdit\EditEngine;
use voku\AgentEdit\Receipt\ApplyRequest;
use voku\AgentEdit\Tests\Support\CachedAgentMapBuilder;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\IndexWriter;
use voku\AgentMap\Scaffold\MethodCopyPlanner;

final class MethodCopyPlanConsumerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-edit-method-copy-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0o775, true);

        file_put_contents($this->root . '/src/ServiceWithDocBlock.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Fixture;

final class ServiceWithDocBlock
{
    private const DEFAULT_MULTIPLIER = 2;

    private int $factor = 1;

    /**
     * Compute a scaled value with custom normalization.
     *
     * @param int $value Raw input value
     * @param int $multiplier Custom multiplier
     * @return int The scaled result
     * @throws \InvalidArgumentException When value is negative
     */
    public function computeScaled(int $value, int $multiplier = self::DEFAULT_MULTIPLIER): int
    {
        if ($value < 0) {
            throw new \InvalidArgumentException('Value must be non-negative.');
        }

        return $value * $multiplier;
    }

    public function existingHelper(): string
    {
        return 'helper';
    }
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

    public function testCopyMethodWithPhpDocBlockIntoSameFileWithoutBreakingCode(): void
    {
        $map = CachedAgentMapBuilder::build($this->root, ['src'], []);
        self::assertStringEndsWith('+phpstan', $map->backend);

        $plan = (new MethodCopyPlanner($this->root))->plan(
            $map,
            'Fixture\\ServiceWithDocBlock::computeScaled',
            'Fixture\\ServiceWithDocBlock',
            'computeScaledDuplicate',
        )->toArray();

        self::assertSame('method_copy_plan', $plan['type']);
        self::assertSame('1.0', $plan['contract_version']);
        self::assertSame('safe', $plan['status']);
        self::assertSame([], $plan['blockers']);
        self::assertSame([], $plan['blind_spots']);
        self::assertSame([], $plan['owner_dependencies']);

        // 1. Preflight validation
        $prepared = (new MethodCopyPlanApplier())->preflight($plan, $map, $this->root);
        self::assertSame('method_copy_plan', $prepared['plan_type']);
        self::assertSame(1, $prepared['edit_count']);
        self::assertSame(0, $prepared['move_count']);

        // 2. Transactional apply
        $result = (new MethodCopyPlanApplier())->apply($plan, $map, $this->root);
        self::assertTrue($result->succeeded());

        $fileContent = (string) file_get_contents($this->root . '/src/ServiceWithDocBlock.php');

        // 3. Verify original method and its docblock remain intact
        self::assertStringContainsString('public function computeScaled(int $value', $fileContent);
        self::assertStringContainsString('Compute a scaled value with custom normalization.', $fileContent);
        self::assertStringContainsString('@throws \InvalidArgumentException When value is negative', $fileContent);

        // 4. Verify new copied method and its docblock exist in the same file
        self::assertStringContainsString('public function computeScaledDuplicate(int $value', $fileContent);
        self::assertStringContainsString('public function existingHelper(): string', $fileContent);

        // Docblock must appear twice now (once for original, once for copy)
        self::assertSame(2, substr_count($fileContent, 'Compute a scaled value with custom normalization.'));
        self::assertSame(2, substr_count($fileContent, '@param int $value Raw input value'));
        self::assertSame(2, substr_count($fileContent, '@return int The scaled result'));

        // 5. Verify valid PHP syntax
        $lintCode = 0;
        $lintOutput = [];
        exec('php -l ' . escapeshellarg($this->root . '/src/ServiceWithDocBlock.php'), $lintOutput, $lintCode);
        self::assertSame(0, $lintCode, 'Resulting PHP file must have valid syntax: ' . implode("\n", $lintOutput));

        // 6. Verify rebuilt Map indexes both methods
        $mapAfter = CachedAgentMapBuilder::build($this->root, ['src'], []);
        $methodNames = [];
        foreach ($mapAfter->files as $file) {
            foreach ($file->symbols as $symbol) {
                foreach ($symbol->methods as $m) {
                    $methodNames[] = $symbol->fqn . '::' . $m->name;
                }
            }
        }
        self::assertContains('Fixture\ServiceWithDocBlock::computeScaled', $methodNames);
        self::assertContains('Fixture\ServiceWithDocBlock::computeScaledDuplicate', $methodNames);
        self::assertContains('Fixture\ServiceWithDocBlock::existingHelper', $methodNames);
    }

    public function testCopyMethodEndToEndWithReceiptAndVerification(): void
    {
        $map = CachedAgentMapBuilder::build($this->root, ['src'], []);
        $plan = (new MethodCopyPlanner($this->root))->plan(
            $map,
            'Fixture\\ServiceWithDocBlock::computeScaled',
            'Fixture\\ServiceWithDocBlock',
            'computeScaledCopy',
        )->toArray();

        $planPath = $this->root . '/plan.json';
        file_put_contents($planPath, json_encode($plan, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        $mapPath = $this->root . '/.agent-map/php-symbols.json';
        (new IndexWriter())->write($map, $mapPath);

        $receiptDir = $this->root . '/.agent-edit/receipts/COPY-1';
        $engine = new EditEngine();

        $receipt = $engine->applyWithReceipt(new ApplyRequest(
            repositoryRoot: $this->root,
            planPath: $planPath,
            mapIndexPath: $mapPath,
            mapRoot: $this->root,
            outputDirectory: $receiptDir,
            label: 'COPY-1',
        ));

        self::assertSame('runner_succeeded', $receipt->status);
        self::assertSame(0, $receipt->exitCode);
        self::assertFileExists($receipt->receiptPath);

        // Verification on rebuilt Map
        $refreshedMap = CachedAgentMapBuilder::build($this->root, ['src'], []);
        (new IndexWriter())->write($refreshedMap, $mapPath);
        $verification = $engine->verify($this->root, $receiptDir, $mapPath, $this->root);

        self::assertSame('1.0', $verification['schema_version']);
        self::assertSame('method_copy_plan_verification', $verification['kind']);
        self::assertSame('incomplete', $verification['status']);
        self::assertSame('passed', $verification['checks']['execution_binding']);
        self::assertSame('passed', $verification['checks']['current_map']);
        self::assertSame('passed', $verification['checks']['replacements']);
    }

    public function testCopyMethodWithPhpDocAndAttributesIntoSameFile(): void
    {
        file_put_contents($this->root . '/src/WithAttributes.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Fixture;

final class WithAttributes
{
    /**
     * Parse structured payload.
     *
     * @param string $json Valid json string
     * @return array Array of parsed data
     */
    #[\Deprecated(message: 'Use parseV2 instead')]
    public function parseLegacy(string $json): array
    {
        return (array) json_decode($json, true);
    }
}
PHP);

        $map = CachedAgentMapBuilder::build($this->root, ['src'], []);
        $plan = (new MethodCopyPlanner($this->root))->plan(
            $map,
            'Fixture\\WithAttributes::parseLegacy',
            'Fixture\\WithAttributes',
            'parseLegacyBackup',
        )->toArray();

        self::assertSame('safe', $plan['status']);

        $result = (new MethodCopyPlanApplier())->apply($plan, $map, $this->root);
        self::assertTrue($result->succeeded());

        $content = (string) file_get_contents($this->root . '/src/WithAttributes.php');
        self::assertStringContainsString('public function parseLegacy(string $json): array', $content);
        self::assertStringContainsString('public function parseLegacyBackup(string $json): array', $content);
        self::assertSame(2, substr_count($content, "Deprecated(message: 'Use parseV2 instead')"));
        self::assertSame(2, substr_count($content, 'Parse structured payload.'));

        $lintCode = 0;
        $lintOutput = [];
        exec('php -l ' . escapeshellarg($this->root . '/src/WithAttributes.php'), $lintOutput, $lintCode);
        self::assertSame(0, $lintCode, 'Resulting PHP file must have valid syntax: ' . implode("\n", $lintOutput));
    }

    public function testCopyMethodWithPhpDocBetweenTwoClassesInSameFile(): void
    {
        file_put_contents($this->root . '/src/TwoClassesSameFile.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Fixture;

final class Producer
{
    /**
     * Format a string value.
     *
     * @param string $value Input string
     * @return string Formatted string
     */
    public function format(string $value): string
    {
        return strtoupper(trim($value));
    }
}

final class Consumer
{
    public function consume(): void
    {
    }
}
PHP);

        $map = CachedAgentMapBuilder::build($this->root, ['src'], []);
        $plan = (new MethodCopyPlanner($this->root))->plan(
            $map,
            'Fixture\\Producer::format',
            'Fixture\\Consumer',
            'formatInConsumer',
        )->toArray();

        self::assertSame('safe', $plan['status']);

        $result = (new MethodCopyPlanApplier())->apply($plan, $map, $this->root);
        self::assertTrue($result->succeeded());

        $content = (string) file_get_contents($this->root . '/src/TwoClassesSameFile.php');

        // Producer still has format() and its docblock
        self::assertStringContainsString('public function format(string $value): string', $content);

        // Consumer now has formatInConsumer() and its docblock
        self::assertStringContainsString('public function formatInConsumer(string $value): string', $content);
        self::assertStringContainsString('public function consume(): void', $content);

        $lintCode = 0;
        $lintOutput = [];
        exec('php -l ' . escapeshellarg($this->root . '/src/TwoClassesSameFile.php'), $lintOutput, $lintCode);
        self::assertSame(0, $lintCode, 'Resulting PHP file must have valid syntax: ' . implode("\n", $lintOutput));
    }

    public function testCopyMethodToDifferentClassAddsUseStatement(): void
    {
        file_put_contents($this->root . '/src/SourceWithCustomType.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Fixture;

final class CustomPayload
{
    public string $data = 'test';
}

final class SourceWithCustomType
{
    /**
     * Create a new custom payload instance.
     *
     * @return CustomPayload
     */
    public function makePayload(): CustomPayload
    {
        return new CustomPayload();
    }
}
PHP);

        file_put_contents($this->root . '/src/OtherDestination.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Fixture\Other;

final class OtherDestination
{
    public function ping(): string
    {
        return 'pong';
    }
}
PHP);

        $map = CachedAgentMapBuilder::build($this->root, ['src'], []);
        $plan = (new MethodCopyPlanner($this->root))->plan(
            $map,
            'Fixture\\SourceWithCustomType::makePayload',
            'Fixture\\Other\\OtherDestination',
            'makePayload',
        )->toArray();

        self::assertSame('safe', $plan['status']);

        $result = (new MethodCopyPlanApplier())->apply($plan, $map, $this->root);
        self::assertTrue($result->succeeded());

        $destContent = (string) file_get_contents($this->root . '/src/OtherDestination.php');
        self::assertStringContainsString('use Fixture\CustomPayload;', $destContent);
        self::assertStringContainsString('public function makePayload(): CustomPayload', $destContent);
        self::assertStringContainsString('Create a new custom payload instance.', $destContent);

        $lintCode = 0;
        $lintOutput = [];
        exec('php -l ' . escapeshellarg($this->root . '/src/OtherDestination.php'), $lintOutput, $lintCode);
        self::assertSame(0, $lintCode, 'Resulting destination PHP file must have valid syntax: ' . implode("\n", $lintOutput));
    }

    public function testSameClassCopyWithoutNewMethodNameFailsClosed(): void
    {
        $map = CachedAgentMapBuilder::build($this->root, ['src'], []);
        $plan = (new MethodCopyPlanner($this->root))->plan(
            $map,
            'Fixture\\ServiceWithDocBlock::computeScaled',
            'Fixture\\ServiceWithDocBlock',
            'computeScaled', // duplicate name in same class
        )->toArray();

        self::assertSame('blocked', $plan['status']);
        self::assertNotEmpty($plan['blockers']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not safe');
        (new MethodCopyPlanApplier())->preflight($plan, $map, $this->root);
    }

    public function testReviewRequiredPlanFailsClosedBeforeMutation(): void
    {
        file_put_contents($this->root . '/src/WithThisDependency.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Fixture;

final class WithThisDependency
{
    private int $internalState = 42;

    /**
     * Access internal owner state.
     */
    public function getState(): int
    {
        return $this->internalState;
    }
}

final class SeparateClass
{
}
PHP);

        $map = CachedAgentMapBuilder::build($this->root, ['src'], []);
        $plan = (new MethodCopyPlanner($this->root))->plan(
            $map,
            'Fixture\\WithThisDependency::getState',
            'Fixture\\SeparateClass',
            'getStateCopied',
        )->toArray();

        // Copying a method with $this dependency to a different class requires review
        self::assertSame('review_required', $plan['status']);
        self::assertNotEmpty($plan['blind_spots']);

        $before = (string) file_get_contents($this->root . '/src/WithThisDependency.php');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('requires explicit review');

        try {
            (new MethodCopyPlanApplier())->preflight($plan, $map, $this->root);
        } finally {
            self::assertSame($before, (string) file_get_contents($this->root . '/src/WithThisDependency.php'));
        }
    }

    public function testSyntaxFailureDuringStagingLeavesSourceUntouched(): void
    {
        $map = CachedAgentMapBuilder::build($this->root, ['src'], []);
        $plan = (new MethodCopyPlanner($this->root))->plan(
            $map,
            'Fixture\\ServiceWithDocBlock::computeScaled',
            'Fixture\\ServiceWithDocBlock',
            'computeScaledCopy',
        )->toArray();

        $before = (string) file_get_contents($this->root . '/src/ServiceWithDocBlock.php');

        $applier = new MethodCopyPlanApplier(
            lintOperation: static fn (string $path): array => [
                'exit_code' => 1,
                'stdout' => '',
                'stderr' => 'simulated lint error on staging ' . basename($path),
            ],
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Rewritten PHP failed syntax validation');

        try {
            $applier->apply($plan, $map, $this->root);
        } finally {
            self::assertSame($before, (string) file_get_contents($this->root . '/src/ServiceWithDocBlock.php'));
        }
    }

    public function testRequiresPhpStanBackedCurrentMap(): void
    {
        $map = CachedAgentMapBuilder::build($this->root, ['src'], []);
        $plan = (new MethodCopyPlanner($this->root))->plan(
            $map,
            'Fixture\\ServiceWithDocBlock::computeScaled',
            'Fixture\\ServiceWithDocBlock',
            'computeScaledCopy',
        )->toArray();

        $structural = new AgentMapIndex(
            schemaVersion: $map->schemaVersion,
            root: $map->root,
            backend: 'simple-php-code-parser+structural-only',
            files: $map->files,
            relations: $map->relations,
            diagnostics: $map->diagnostics,
            fingerprint: $map->fingerprint,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('requires a PHPStan-backed current map');
        (new MethodCopyPlanApplier())->preflight($plan, $structural, $this->root);
    }
}
