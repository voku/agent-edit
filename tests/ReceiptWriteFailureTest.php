<?php

declare(strict_types=1);

namespace voku\AgentEdit\Tests;

use Closure;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentEdit\Apply\EditResult;
use voku\AgentEdit\Apply\PlanApplier;
use voku\AgentEdit\Apply\RenamePlanApplier;
use voku\AgentEdit\Receipt\ApplyRequest;
use voku\AgentEdit\Receipt\ReceiptNotPersistedException;
use voku\AgentEdit\EditEngine;
use voku\AgentEdit\Tests\Support\CachedAgentMapBuilder;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\IndexWriter;

/**
 * What happens when the receipt cannot be written AFTER the source was published (or after a failed attempt was
 * rolled back). The lock is already released at that point, so there is nothing to undo atomically: the contract
 * is "never silent, never masked, never a receipt that lies".
 */
final class ReceiptWriteFailureTest extends TestCase
{
    private string $root;
    private AgentMapIndex $map;
    private string $mapPath;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-edit-receipt-write-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0o775, true);
        file_put_contents($this->root . '/src/Service.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace Demo;\n\nfinal class Service\n{\n    public function oldName(): void\n    {\n    }\n}\n");
        $this->map = CachedAgentMapBuilder::build($this->root, ['src'], []);
        $this->mapPath = $this->root . '/map.json';
        (new IndexWriter())->write($this->map, $this->mapPath);
        file_put_contents($this->root . '/plan.json', json_encode($this->plan(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        exec('cd ' . escapeshellarg($this->root) . ' && git init -q . && printf "map.json\\nplan.json\\n.agent-edit/\\n" > .gitignore && git add -A && git -c user.email=t@example.invalid -c user.name=t commit -qm init', $output, $exit);
        self::assertSame(0, $exit, implode("\n", $output));
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

    public function testReceiptWriteFailureAfterPublicationIsAnExplicitTypedFailureNamingTheChangedFiles(): void
    {
        $bundle = $this->root . '/.agent-edit/receipts/POST-PUBLISH';
        $engine = $this->engineBlockingReceiptAfterApply($bundle);

        try {
            $engine->applyWithReceipt($this->request($bundle, 'POST-PUBLISH'));
            self::fail('Expected the receipt write to fail.');
        } catch (ReceiptNotPersistedException $exception) {
            self::assertSame(['src/Service.php'], $exception->changedFiles);
            self::assertStringContainsString('published', $exception->getMessage());
            self::assertStringContainsString('no receipt exists', $exception->getMessage());
            self::assertStringContainsString('src/Service.php', $exception->getMessage());
        }

        // The source stayed published: there is no snapshot left to roll back to once the transaction committed.
        self::assertStringContainsString('function newName', (string) file_get_contents($this->root . '/src/Service.php'));
        // ...and no receipt file was produced, so verify (and any governed close) refuse the bundle.
        self::assertFileDoesNotExist($bundle . '/' . EditEngine::RECEIPT_FILE . '.tmp-' . getmypid());
        self::assertTrue(is_dir($bundle . '/' . EditEngine::RECEIPT_FILE), 'the blocker stays a directory, never a half-written receipt file');
        $this->expectException(RuntimeException::class);
        (new EditEngine())->verify($this->root, '.agent-edit/receipts/POST-PUBLISH', $this->mapPath);
    }

    public function testFailureReceiptWriteFailureNeverMasksTheOriginalApplyFailure(): void
    {
        $bundle = $this->root . '/.agent-edit/receipts/MASKED';
        $failing = new class ($bundle) implements PlanApplier {
            public function __construct(private string $bundle)
            {
            }

            public function apply(array $plan, AgentMapIndex $map, string $root): EditResult
            {
                mkdir($this->bundle . '/' . EditEngine::RECEIPT_FILE . '/keep', 0o775, true);

                throw new RuntimeException('simulated publication failure; every source file was restored');
            }

            public function preflight(array $plan, AgentMapIndex $map, string $root): array
            {
                return (new RenamePlanApplier())->preflight($plan, $map, $root);
            }
        };
        $engine = new EditEngine(applierOverrides: [RenamePlanApplier::class => $failing]);

        try {
            $engine->applyWithReceipt($this->request($bundle, 'MASKED'));
            self::fail('Expected the original apply failure.');
        } catch (RuntimeException $exception) {
            self::assertNotInstanceOf(ReceiptNotPersistedException::class, $exception);
            self::assertStringContainsString('simulated publication failure', $exception->getMessage());
        }
    }

    /** Applier that really applies, then makes the receipt path unwritable (works for root too: a non-empty directory). */
    private function engineBlockingReceiptAfterApply(string $bundle): EditEngine
    {
        $real = new RenamePlanApplier();
        $blocking = new class ($real, $bundle) implements PlanApplier {
            public function __construct(private RenamePlanApplier $inner, private string $bundle)
            {
            }

            public function apply(array $plan, AgentMapIndex $map, string $root): EditResult
            {
                $result = $this->inner->apply($plan, $map, $root);
                mkdir($this->bundle . '/' . EditEngine::RECEIPT_FILE . '/keep', 0o775, true);

                return $result;
            }

            public function preflight(array $plan, AgentMapIndex $map, string $root): array
            {
                return $this->inner->preflight($plan, $map, $root);
            }
        };

        return new EditEngine(applierOverrides: [RenamePlanApplier::class => $blocking]);
    }

    private function request(string $bundle, string $label): ApplyRequest
    {
        return new ApplyRequest(
            repositoryRoot: $this->root,
            planPath: $this->root . '/plan.json',
            mapIndexPath: $this->mapPath,
            mapRoot: $this->root,
            outputDirectory: $bundle,
            label: $label,
        );
    }

    /** @return array<string, mixed> */
    private function plan(): array
    {
        $source = (string) file_get_contents($this->root . '/src/Service.php');
        $start = strpos($source, 'oldName');
        self::assertIsInt($start);
        $file = $this->map->file('src/Service.php');
        self::assertNotNull($file);
        $target = 'method:Demo\\Service::oldName';

        return [
            'type' => 'method_rename_plan',
            'contract_version' => '1.0',
            'status' => 'safe',
            'target_id' => $target,
            'provenance' => [
                'map_digest' => $this->map->mapDigest(),
                'backend' => $this->map->backend,
                'analysis_fingerprint' => $this->map->fingerprint?->toArray(),
            ],
            'edits' => [[
                'path' => 'src/Service.php',
                'source_sha256' => $file->sha256,
                'start_file_pos' => $start,
                'end_file_pos' => $start + strlen('oldName') - 1,
                'line_start' => 9,
                'line_end' => 9,
                'expected' => 'oldName',
                'replacement' => 'newName',
                'role' => 'declaration',
                'symbol_id' => $target,
                'resolution' => 'parser_resolved',
            ]],
            'blind_spots' => [],
            'stale_evidence' => [],
            'blockers' => [],
            'not_observable' => [],
        ];
    }
}
