<?php

declare(strict_types=1);

namespace voku\AgentEdit\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentEdit\EditEngine;
use voku\AgentEdit\Receipt\ApplyRequest;
use voku\AgentEdit\Tests\Support\CachedAgentMapBuilder;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\IndexWriter;

/**
 * A refusal that produces no receipt must not leave an empty bundle behind. A host that counts every bundle directory of
 * a task reads an empty one as a missing verification result and blocks a governed close that never edited anything.
 */
final class RefusalLeavesNoBundleTest extends TestCase
{
    private string $root;
    private AgentMapIndex $map;
    private string $mapPath;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-edit-refusal-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0o775, true);
        file_put_contents($this->root . '/src/Service.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace Demo;\n\nfinal class Service\n{\n    public function oldName(): void\n    {\n    }\n}\n");
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

    public function testABlockedPlanLeavesNoBundleAndNoCreatedParents(): void
    {
        $plan = $this->plan();
        $plan['status'] = 'blocked';
        $plan['blockers'] = ['Method is called at src/Caller.php:3-3 (phpstan_resolved).'];

        $this->expectRefusal($plan, '.agent-edit/receipts/BLOCKED', dryRun: true);

        self::assertDirectoryDoesNotExist($this->root . '/.agent-edit', 'directories created for the refused attempt are removed too');
    }

    public function testABlockedPlanInARealApplyKeepsItsFailureReceipt(): void
    {
        $plan = $this->plan();
        $plan['status'] = 'blocked';
        $this->writePlan($plan);

        try {
            (new EditEngine())->applyWithReceipt($this->request('.agent-edit/receipts/ATTEMPT'));
            self::fail('Expected the blocked plan to be refused.');
        } catch (RuntimeException) {
        }

        // An authorized mutation attempt that fails is evidence, not a leftover: it stays as a runner_failed receipt.
        $receipt = json_decode((string) file_get_contents($this->root . '/.agent-edit/receipts/ATTEMPT/' . EditEngine::RECEIPT_FILE), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('runner_failed', $receipt['status']);
        self::assertSame([], $receipt['changed_files']);
    }

    public function testAnUnsupportedPlanTypeLeavesNoBundle(): void
    {
        $plan = $this->plan();
        $plan['type'] = 'method_teleport_plan';

        $this->expectRefusal($plan, '.agent-edit/receipts/UNSUPPORTED', InvalidArgumentException::class);
    }

    public function testAHostAuthorizationRefusalLeavesNoBundle(): void
    {
        $this->writePlan($this->plan());
        $request = $this->request('.agent-edit/receipts/DENIED', static function (string $label): void {
            throw new RuntimeException('task ' . $label . ' is not ready for mutation');
        });

        try {
            (new EditEngine())->applyWithReceipt($request);
            self::fail('Expected the authorization refusal.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('not ready for mutation', $exception->getMessage());
        }

        self::assertDirectoryDoesNotExist($this->root . '/.agent-edit/receipts/DENIED');
    }

    public function testAnExistingBundleDirectoryIsNeverRemoved(): void
    {
        mkdir($this->root . '/.agent-edit/receipts/KEEP', 0o775, true);
        $plan = $this->plan();
        $plan['status'] = 'blocked';

        $this->expectRefusal($plan, '.agent-edit/receipts/KEEP', null, keepBundle: true, dryRun: true);

        self::assertDirectoryExists($this->root . '/.agent-edit/receipts/KEEP');
    }

    public function testAParentThatAlreadyHeldOtherContentIsKept(): void
    {
        mkdir($this->root . '/.agent-edit/receipts/OTHER', 0o775, true);
        file_put_contents($this->root . '/.agent-edit/receipts/OTHER/execution.json', '{}');
        $plan = $this->plan();
        $plan['status'] = 'blocked';

        $this->expectRefusal($plan, '.agent-edit/receipts/NEW', dryRun: true);

        self::assertFileExists($this->root . '/.agent-edit/receipts/OTHER/execution.json');
    }

    public function testASuccessfulDryRunKeepsItsReceiptBundle(): void
    {
        $this->writePlan($this->plan());

        $receipt = (new EditEngine())->applyWithReceipt($this->request('.agent-edit/receipts/DRY', dryRun: true));

        self::assertSame('prepared', $receipt->status);
        self::assertFileExists($this->root . '/.agent-edit/receipts/DRY/' . EditEngine::RECEIPT_FILE);
    }

    /**
     * @param array<string, mixed> $plan
     * @param class-string<\Throwable>|null $exceptionClass
     */
    private function expectRefusal(array $plan, string $bundle, ?string $exceptionClass = null, bool $keepBundle = false, bool $dryRun = false): void
    {
        $this->writePlan($plan);
        try {
            (new EditEngine())->applyWithReceipt($this->request($bundle, dryRun: $dryRun));
            self::fail('Expected the plan to be refused.');
        } catch (\Throwable $exception) {
            if ($exceptionClass !== null) {
                self::assertInstanceOf($exceptionClass, $exception);
            }
        }

        if (!$keepBundle) {
            self::assertDirectoryDoesNotExist($this->root . '/' . $bundle);
        }
        self::assertStringContainsString('oldName', (string) file_get_contents($this->root . '/src/Service.php'), 'the refused plan changed nothing');
    }

    /** @param array<string, mixed> $plan */
    private function writePlan(array $plan): void
    {
        file_put_contents($this->root . '/plan.json', json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /** @param (\Closure(string): void)|null $authorize */
    private function request(string $bundle, ?\Closure $authorize = null, bool $dryRun = false): ApplyRequest
    {
        return new ApplyRequest(
            repositoryRoot: $this->root,
            planPath: $this->root . '/plan.json',
            mapIndexPath: $this->mapPath,
            mapRoot: $this->root,
            outputDirectory: $this->root . '/' . $bundle,
            label: basename($bundle),
            dryRun: $dryRun,
            authorizeMutation: $authorize,
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
