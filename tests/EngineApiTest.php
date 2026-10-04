<?php

declare(strict_types=1);

namespace voku\AgentEdit\Tests;

use Closure;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentEdit\Cli\CliApplication;
use voku\AgentEdit\Apply\MutationLock;
use RuntimeException;
use voku\AgentEdit\Receipt\ApplyRequest;
use voku\AgentEdit\EditEngine;
use voku\AgentEdit\Apply\RenamePlanApplier;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\IndexWriter;
use voku\AgentEdit\Tests\Support\CachedAgentMapBuilder;

final class EngineApiTest extends TestCase
{
    private string $root;
    private AgentMapIndex $map;
    private string $mapPath;
    private string $planPath;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-edit-engine-api-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0o775, true);
        file_put_contents($this->root . '/src/Service.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Demo;

final class Service
{
    public function oldName(): void
    {
    }
}
PHP);

        $this->map = CachedAgentMapBuilder::build($this->root, ['src'], []);
        $this->mapPath = $this->root . '/map.json';
        (new IndexWriter())->write($this->map, $this->mapPath);
        $this->planPath = $this->root . '/plan.json';
        file_put_contents($this->planPath, json_encode($this->plan(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        exec('cd ' . escapeshellarg($this->root) . ' && git init -q . && printf "map.json\\nplan.json\\n.agent-edit/\\n" > .gitignore && git add -A && git -c user.email=t@example.invalid -c user.name=t commit -qm init');
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

    public function testApplyWithReceiptThenVerifyThroughThePublicApiOnly(): void
    {
        $engine = new EditEngine();
        $receipt = $engine->applyWithReceipt($this->request('API-1'));

        self::assertSame('runner_succeeded', $receipt->status);
        self::assertTrue($receipt->succeeded());
        self::assertStringContainsString('function newName', (string) file_get_contents($this->root . '/src/Service.php'));

        $rebuilt = CachedAgentMapBuilder::build($this->root, ['src'], []);
        (new IndexWriter())->write($rebuilt, $this->mapPath);
        $result = $engine->verify($this->root, '.agent-edit/receipts/API-1', $this->mapPath);

        self::assertSame('method_rename_plan', $result['plan']['type']);
        self::assertFileExists($this->root . '/.agent-edit/receipts/API-1/' . EditEngine::VERIFICATION_FILE);
    }

    public function testAuthorizationHookRefusalLeavesSourceUntouched(): void
    {
        $before = (string) file_get_contents($this->root . '/src/Service.php');
        $request = $this->request('API-DENIED', authorize: static function (string $label): void {
            throw new RuntimeException('task ' . $label . ' is not ready for mutation');
        });

        try {
            (new EditEngine())->applyWithReceipt($request);
            self::fail('Expected the authorization hook to refuse the mutation.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('not ready for mutation', $exception->getMessage());
        }

        self::assertSame($before, file_get_contents($this->root . '/src/Service.php'));
        self::assertFileDoesNotExist($this->root . '/.agent-edit/receipts/API-DENIED/' . EditEngine::RECEIPT_FILE);
    }

    public function testDryRunSkipsTheAuthorizationHookAndWritesNoSource(): void
    {
        $before = (string) file_get_contents($this->root . '/src/Service.php');
        $called = false;
        $receipt = (new EditEngine())->applyWithReceipt($this->request('API-DRY', dryRun: true, authorize: static function () use (&$called): void {
            $called = true;
        }));

        self::assertSame('prepared', $receipt->status);
        self::assertFalse($called);
        self::assertSame($before, file_get_contents($this->root . '/src/Service.php'));
    }

    public function testVerifyRejectsABundleOutsideTheRepositoryRoot(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('escapes the project root');

        (new EditEngine())->verify($this->root, sys_get_temp_dir(), $this->mapPath);
    }

    /** @param (\Closure(string): void)|null $authorize */
    private function request(string $label, bool $dryRun = false, ?\Closure $authorize = null): ApplyRequest
    {
        return new ApplyRequest(
            repositoryRoot: $this->root,
            planPath: $this->planPath,
            mapIndexPath: $this->mapPath,
            mapRoot: $this->root,
            outputDirectory: $this->root . '/.agent-edit/receipts/' . $label,
            label: $label,
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
