<?php

declare(strict_types=1);

namespace voku\AgentEdit\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentEdit\EditEngine;
use voku\AgentEdit\Preview\ChangePreview;
use voku\AgentEdit\Preview\UnifiedDiff;
use voku\AgentEdit\Receipt\ApplyRequest;
use voku\AgentEdit\Tests\Support\CachedAgentMapBuilder;
use voku\AgentEdit\Tests\Support\ScratchProject;
use voku\AgentEdit\Tests\Support\UnifiedPatch;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\IndexWriter;
use voku\AgentMap\Removal\ClassRemovalPlanner;

/** A dry run must say what an apply would do, and what it says must be exactly what the apply then does. */
final class ChangePreviewTest extends TestCase
{
    use ScratchProject;

    private const SERVICE = "<?php\n\ndeclare(strict_types=1);\n\nnamespace Demo;\n\nfinal class Service\n{\n    public function oldName(): string\n    {\n        return 'x';\n    }\n\n    public function other(): string\n    {\n        return \$this->oldName();\n    }\n}\n";

    protected function setUp(): void
    {
        $this->createScratchProject('agent-edit-preview-');
    }

    protected function tearDown(): void
    {
        $this->removeScratchProject();
    }

    public function testRenameDryRunListsEveryChangedFileWithCountsAndLeavesSourceUntouched(): void
    {
        $this->write('src/Service.php', self::SERVICE);
        $this->write('src/Caller.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace Demo;\n\nfinal class Caller\n{\n    public function run(Service \$service): string\n    {\n        return \$service->oldName();\n    }\n}\n");
        $map = $this->map();
        $plan = $this->renamePlan($map);

        $dry = $this->engine($plan, $map, dryRun: true, output: '.agent-edit/receipts/DRY');

        self::assertSame('prepared', $dry->status);
        self::assertSame(self::SERVICE, $this->read('src/Service.php'));
        self::assertSame([], $dry->changedFiles, 'a dry run observes no changed file');
        self::assertSame([
            ['path' => 'src/Caller.php', 'change' => 'modified', 'lines_added' => 1, 'lines_removed' => 1],
            ['path' => 'src/Service.php', 'change' => 'modified', 'lines_added' => 2, 'lines_removed' => 2],
        ], $dry->plannedChanges);
        self::assertStringContainsString("--- a/src/Caller.php\n+++ b/src/Caller.php\n", $dry->diff);
        self::assertStringContainsString("-        return \$service->oldName();\n+        return \$service->newName();\n", $dry->diff);

        $receipt = json_decode($this->read('.agent-edit/receipts/DRY/execution.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($dry->plannedChanges, $receipt['planned_changes'], 'the persisted dry-run receipt records the same summary');
        self::assertStringNotContainsString('--- a/', $this->read('.agent-edit/receipts/DRY/execution.json'), 'the diff itself is never persisted');
    }

    public function testThePreviewIsExactlyWhatTheRealApplyThenDoes(): void
    {
        $this->write('src/Service.php', self::SERVICE);
        $map = $this->map();
        $plan = $this->renamePlan($map);
        $dry = $this->engine($plan, $map, dryRun: true, output: '.agent-edit/receipts/DRY');

        $real = $this->engine($plan, $map, dryRun: false, output: '.agent-edit/receipts/REAL');

        $after = $this->read('src/Service.php');
        self::assertNotSame(self::SERVICE, $after);
        self::assertSame(['src/Service.php'], $real->changedFiles);
        self::assertSame('', $real->diff, 'a real apply carries no preview');
        self::assertSame([], $real->plannedChanges);
        self::assertSame(UnifiedDiff::between(self::SERVICE, $after, 'a/src/Service.php', 'b/src/Service.php')['diff'], $dry->diff, 'preview diff equals the diff of the real before/after');
        self::assertSame($after, UnifiedPatch::apply(self::SERVICE, $dry->diff), 'applying the previewed diff to the old file reproduces the real result');
    }

    public function testClassRemovalPreviewShowsTheDeletionAsAWholeFileRemoval(): void
    {
        $legacy = "<?php\n\ndeclare(strict_types=1);\n\nnamespace Demo;\n\nfinal class Legacy\n{\n    public function run(): void\n    {\n    }\n}\n";
        $this->write('src/Legacy.php', $legacy);
        $this->write('src/Keep.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace Demo;\n\nfinal class Keep\n{\n}\n");
        $map = $this->map();
        $plan = (new ClassRemovalPlanner())->plan($map, 'Demo\\Legacy')->toArray();
        self::assertSame('safe', $plan['status']);

        $dry = $this->engine($plan, $map, dryRun: true, output: '.agent-edit/receipts/DRY');

        self::assertSame([['path' => 'src/Legacy.php', 'change' => 'deleted', 'lines_added' => 0, 'lines_removed' => 12]], $dry->plannedChanges);
        self::assertStringContainsString("--- a/src/Legacy.php\n+++ /dev/null\n@@ -1,12 +0,0 @@\n", $dry->diff);
        self::assertSame('', UnifiedPatch::apply($legacy, $dry->diff));
        self::assertSame($legacy, $this->read('src/Legacy.php'), 'a dry run deletes nothing');
    }

    public function testClassMovePreviewReportsTheMoveTogetherWithTheRewrite(): void
    {
        $service = "<?php\n\ndeclare(strict_types=1);\n\nnamespace Demo\\Old;\n\nfinal class Service\n{\n}\n";
        $this->write('src/Old/Service.php', $service);
        $built = CachedAgentMapBuilder::build($this->root, ['src'], []);
        $map = new AgentMapIndex($built->schemaVersion, $built->root, 'simple-php-code-parser+structural-only', $built->files, $built->relations, $built->diagnostics, $built->fingerprint);
        $needle = 'Demo\\Old';
        $start = (int) strpos($service, $needle);
        $file = $map->file('src/Old/Service.php');
        self::assertNotNull($file);
        $plan = [
            'type' => 'class_move_plan', 'contract_version' => '1.0', 'status' => 'safe',
            'target_id' => 'class:Demo\\Old\\Service', 'source_fqn' => 'Demo\\Old\\Service', 'destination_fqn' => 'Demo\\New\\Service',
            'provenance' => ['map_digest' => $map->mapDigest(), 'backend' => $map->backend, 'analysis_fingerprint' => $map->fingerprint?->toArray()],
            'autoload' => ['source_prefix' => 'Demo\\', 'source_directory' => 'src', 'destination_prefix' => 'Demo\\', 'destination_directory' => 'src', 'destination_path' => 'src/New/Service.php'],
            'edits' => [['path' => 'src/Old/Service.php', 'source_sha256' => $file->sha256, 'start_file_pos' => $start, 'end_file_pos' => $start + strlen($needle) - 1, 'line_start' => 5, 'line_end' => 5, 'expected' => $needle, 'replacement' => 'Demo\\New', 'role' => 'namespace_declaration', 'symbol_id' => 'class:Demo\\Old\\Service', 'resolution' => 'parser_resolved']],
            'moves' => [['from_path' => 'src/Old/Service.php', 'to_path' => 'src/New/Service.php', 'source_sha256' => $file->sha256, 'reason' => 'Fixture PSR-4 move.', 'destination_must_be_absent' => true]],
            'blind_spots' => [], 'stale_evidence' => [], 'blockers' => [], 'not_observable' => [],
        ];

        $dry = $this->engine($plan, $map, dryRun: true, output: '.agent-edit/receipts/DRY');

        self::assertSame([['path' => 'src/Old/Service.php', 'change' => 'moved_and_modified', 'final_path' => 'src/New/Service.php', 'lines_added' => 1, 'lines_removed' => 1]], $dry->plannedChanges);
        self::assertStringContainsString("--- a/src/Old/Service.php\n+++ b/src/New/Service.php\n", $dry->diff);
        self::assertSame(str_replace('Demo\\Old', 'Demo\\New', $service), UnifiedPatch::apply($service, $dry->diff));
        self::assertFileDoesNotExist($this->root . '/src/New/Service.php');
        self::assertDirectoryDoesNotExist($this->root . '/src/New');
    }

    public function testAMoveWithoutAnyContentChangeIsReportedAsMovedWithARenameHeader(): void
    {
        $this->write('src/Service.php', self::SERVICE);
        $source = $this->root . '/src/Service.php';

        $preview = ChangePreview::fromPreflight([
            'files' => [$source => self::SERVICE],
            'final_paths' => [$source => $this->root . '/src/Moved/Service.php'],
            'source_hashes' => [$source => 'sha256:' . hash('sha256', self::SERVICE)],
        ], $this->root);

        self::assertSame([['path' => 'src/Service.php', 'change' => 'moved', 'final_path' => 'src/Moved/Service.php', 'lines_added' => 0, 'lines_removed' => 0]], $preview['changes']);
        self::assertSame("rename from src/Service.php\nrename to src/Moved/Service.php\n", $preview['diff']);
    }

    public function testAnUnreadableSourceIsAFailureNotAnEmptyPreview(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unable to read source for the change preview');

        ChangePreview::fromPreflight(['files' => [$this->root . '/src/Gone.php' => 'x'], 'final_paths' => [], 'source_hashes' => []], $this->root);
    }

    public function testPathsOutsideTheRootStayAbsoluteInsteadOfBeingMangled(): void
    {
        $outside = sys_get_temp_dir() . '/agent-edit-outside-' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($outside, "a\n");
        try {
            $preview = ChangePreview::fromPreflight(['files' => [$outside => "b\n"], 'final_paths' => [$outside => $outside], 'source_hashes' => []], $this->root);
        } finally {
            unlink($outside);
        }

        self::assertSame($outside, $preview['changes'][0]['path']);
    }

    public function testDeletionsAreOnlyReportedWhenThePlanDeclaresThem(): void
    {
        $this->write('src/Service.php', self::SERVICE);
        $source = $this->root . '/src/Service.php';

        $none = ChangePreview::fromPreflight(['files' => [], 'final_paths' => [], 'source_hashes' => [$source => 'sha256:x']], $this->root);
        $deleted = ChangePreview::fromPreflight(['files' => [], 'final_paths' => [], 'source_hashes' => [$source => 'sha256:x'], 'deletion_count' => 1], $this->root);

        self::assertSame([], $none['changes'], 'a source hash alone is evidence, not a deletion');
        self::assertSame('deleted', $deleted['changes'][0]['change']);
    }

    private function map(): AgentMapIndex
    {
        return CachedAgentMapBuilder::build($this->root, ['src'], []);
    }

    /** @return array<string, mixed> */
    private function renamePlan(AgentMapIndex $map): array
    {
        $source = $this->read('src/Service.php');
        $file = $map->file('src/Service.php');
        self::assertNotNull($file);
        $target = 'method:Demo\\Service::oldName';
        $edit = static fn (string $path, string $sha, int $start, string $role): array => [
            'path' => $path, 'source_sha256' => $sha, 'start_file_pos' => $start, 'end_file_pos' => $start + strlen('oldName') - 1,
            'line_start' => 1, 'line_end' => 1, 'expected' => 'oldName', 'replacement' => 'newName', 'role' => $role, 'symbol_id' => $target, 'resolution' => 'parser_resolved',
        ];
        $edits = [];
        foreach ([[strpos($source, 'function oldName') + strlen('function '), 'declaration'], [strpos($source, '->oldName') + 2, 'call']] as [$start, $role]) {
            $edits[] = $edit('src/Service.php', $file->sha256, (int) $start, $role);
        }
        $caller = $map->file('src/Caller.php');
        if ($caller !== null) {
            $callerSource = $this->read('src/Caller.php');
            $edits[] = $edit('src/Caller.php', $caller->sha256, (int) strpos($callerSource, '->oldName') + 2, 'call');
        }

        return [
            'type' => 'method_rename_plan', 'contract_version' => '1.0', 'status' => 'safe', 'target_id' => $target,
            'provenance' => ['map_digest' => $map->mapDigest(), 'backend' => $map->backend, 'analysis_fingerprint' => $map->fingerprint?->toArray()],
            'edits' => $edits, 'blind_spots' => [], 'stale_evidence' => [], 'blockers' => [], 'not_observable' => [],
        ];
    }

    /** @param array<string, mixed> $plan */
    private function engine(array $plan, AgentMapIndex $map, bool $dryRun, string $output): \voku\AgentEdit\Receipt\EditReceipt
    {
        $planPath = $this->root . '/plan.json';
        $mapPath = $this->root . '/map.json';
        file_put_contents($planPath, json_encode($plan, JSON_THROW_ON_ERROR));
        (new IndexWriter())->write($map, $mapPath);

        return (new EditEngine())->applyWithReceipt(new ApplyRequest(
            repositoryRoot: $this->root,
            planPath: $planPath,
            mapIndexPath: $mapPath,
            mapRoot: $this->root,
            outputDirectory: $this->root . '/' . $output,
            label: basename($output),
            dryRun: $dryRun,
        ));
    }
}
