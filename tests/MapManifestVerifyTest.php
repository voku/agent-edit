<?php

declare(strict_types=1);

namespace voku\AgentEdit\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentEdit\Cli\VerifyCommand;
use voku\AgentEdit\EditEngine;
use voku\AgentEdit\Receipt\ApplyRequest;
use voku\AgentEdit\Tests\Support\CachedAgentMapBuilder;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\IndexWriter;

/** Git-free verification: Map-indexed files are observed through a bound manifest, and the verdict is never `passed`. */
final class MapManifestVerifyTest extends TestCase
{
    private string $root;
    private AgentMapIndex $map;
    private string $mapPath;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-edit-map-manifest-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0o775, true);
        mkdir($this->root . '/tools', 0o775, true);
        file_put_contents($this->root . '/src/Service.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace Demo;\n\nfinal class Service\n{\n    public function oldName(): void\n    {\n    }\n}\n");
        file_put_contents($this->root . '/src/Other.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace Demo;\n\nfinal class Other\n{\n}\n");
        $this->map = CachedAgentMapBuilder::build($this->root, ['src'], []);
        $this->mapPath = $this->root . '/map.json';
        (new IndexWriter())->write($this->map, $this->mapPath);
        file_put_contents($this->root . '/plan.json', json_encode($this->plan(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
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

    public function testReceiptBindsAManifestAndVerifyIsIncompleteNotPassed(): void
    {
        $receipt = $this->applyAndRefreshMap();

        self::assertSame('map_manifest_diff', $receipt['changed_files_source']);
        self::assertSame(['src/Service.php'], $receipt['changed_files']);
        self::assertSame(['source', 'path', 'sha256'], array_keys($receipt['scope_evidence']));
        self::assertSame('map-scope-before.json', $receipt['scope_evidence']['path']);
        $manifestRaw = (string) file_get_contents($this->bundle() . '/map-scope-before.json');
        self::assertSame('sha256:' . hash('sha256', $manifestRaw), $receipt['scope_evidence']['sha256']);
        $manifest = json_decode($manifestRaw, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['src/Other.php', 'src/Service.php'], array_keys($manifest['files']));

        $result = (new EditEngine())->verify($this->root, '.agent-edit/receipts/MANIFEST', $this->mapPath);

        self::assertSame('incomplete', $result['status']);
        self::assertSame(['src/Service.php'], $result['changed_files']);
        self::assertSame('map_indexed_files_only', $result['checks']['changed_files']);
        self::assertSame('scope_unproven', $result['scope']['status']);
        self::assertSame('map_indexed_files', $result['scope']['proven']);
        self::assertSame('files_outside_map_index', $result['scope']['unproven']);
        $persisted = json_decode((string) file_get_contents($this->bundle() . '/verification-result.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('incomplete', $persisted['status']);
    }

    public function testCliNeverReportsPassedAndExitsNonZeroForIncompleteScope(): void
    {
        $this->applyAndRefreshMap();

        ob_start();
        $exit = (new VerifyCommand($this->root))->run(['--bundle=.agent-edit/receipts/MANIFEST', '--map-index=' . $this->mapPath]);
        $output = (string) ob_get_clean();

        self::assertSame(3, $exit);
        self::assertStringContainsString('verification: incomplete', $output);
        self::assertStringNotContainsString('verification: passed', $output);
    }

    public function testChangeToANonIndexedFileIsNeverReadAsAllClean(): void
    {
        $this->applyAndRefreshMap();
        file_put_contents($this->root . '/tools/unobserved.php', "<?php\n// changed outside the Map index\n");

        $result = (new EditEngine())->verify($this->root, '.agent-edit/receipts/MANIFEST', $this->mapPath);

        self::assertSame('incomplete', $result['status']);
        self::assertSame('files_outside_map_index', $result['scope']['unproven']);
        self::assertNotContains('tools/unobserved.php', $result['changed_files']);
    }

    public function testAnAdditionalChangedIndexedFileOutsideThePlanIsAnError(): void
    {
        $this->applyAndRefreshMap(extraIndexedChange: true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('do not exactly match plan scope');

        (new EditEngine())->verify($this->root, '.agent-edit/receipts/MANIFEST', $this->mapPath);
    }

    public function testATamperedManifestIsRejected(): void
    {
        $this->applyAndRefreshMap();
        file_put_contents($this->bundle() . '/map-scope-before.json', json_encode(['schema_version' => '1.0', 'files' => []]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('missing or changed after execution');

        (new EditEngine())->verify($this->root, '.agent-edit/receipts/MANIFEST', $this->mapPath);
    }

    public function testWithGitTheReceiptCarriesNoManifestAndVerifyStillPasses(): void
    {
        exec('cd ' . escapeshellarg($this->root) . ' && git init -q . && printf "map.json\\nplan.json\\n.agent-edit/\\n" > .gitignore && git add -A && git -c user.email=t@example.invalid -c user.name=t commit -qm init', $output, $exit);
        self::assertSame(0, $exit);

        $receipt = $this->applyAndRefreshMap();

        self::assertSame('git_status_diff', $receipt['changed_files_source']);
        self::assertArrayNotHasKey('scope_evidence', $receipt);
        self::assertFileDoesNotExist($this->bundle() . '/map-scope-before.json');
        $result = (new EditEngine())->verify($this->root, '.agent-edit/receipts/MANIFEST', $this->mapPath);
        self::assertSame('passed', $result['status']);
        self::assertArrayNotHasKey('scope', $result);
    }

    private function bundle(): string
    {
        return $this->root . '/.agent-edit/receipts/MANIFEST';
    }

    /** @return array<string, mixed> decoded receipt */
    private function applyAndRefreshMap(bool $extraIndexedChange = false): array
    {
        (new EditEngine())->applyWithReceipt(new ApplyRequest(
            repositoryRoot: $this->root,
            planPath: $this->root . '/plan.json',
            mapIndexPath: $this->mapPath,
            mapRoot: $this->root,
            outputDirectory: $this->bundle(),
            label: 'MANIFEST',
        ));
        if ($extraIndexedChange) {
            file_put_contents($this->root . '/src/Other.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace Demo;\n\nfinal class Other\n{\n    public function added(): void\n    {\n    }\n}\n");
        }
        (new IndexWriter())->write(CachedAgentMapBuilder::build($this->root, ['src'], []), $this->mapPath);

        return json_decode((string) file_get_contents($this->bundle() . '/execution.json'), true, 512, JSON_THROW_ON_ERROR);
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
