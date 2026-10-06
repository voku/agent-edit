<?php

declare(strict_types=1);

namespace voku\AgentEdit\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentEdit\Cli\CliApplication;
use voku\AgentEdit\EditEngine;
use voku\AgentEdit\Receipt\ApplyRequest;
use voku\AgentEdit\Tests\Support\CachedAgentMapBuilder;
use voku\AgentEdit\Verify\ResidueCheck;
use voku\AgentMap\Index\IndexWriter;

/** The PHP rename is proven; Markdown and template mentions of the old name are residue that keeps verify `incomplete`. */
final class ResidueVerifyTest extends TestCase
{
    private string $root;
    private string $mapPath;
    private string $planPath;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-edit-residue-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0o775, true);
        mkdir($this->root . '/templates', 0o775, true);
        file_put_contents($this->root . '/src/Service.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace Demo;\n\nfinal class Service\n{\n    public function oldName(): void\n    {\n    }\n}\n");
        file_put_contents($this->root . '/README.md', "Call `Service::oldName()` to start.\n");
        file_put_contents($this->root . '/CHANGELOG.md', "- added `Service::oldName()`\n");
        file_put_contents($this->root . '/templates/page.html.twig', "{{ service.oldName() }}\n");

        $map = CachedAgentMapBuilder::build($this->root, ['src'], []);
        $this->mapPath = $this->root . '/map.json';
        (new IndexWriter())->write($map, $this->mapPath);
        $file = $map->file('src/Service.php');
        self::assertNotNull($file);
        $start = strpos((string) file_get_contents($this->root . '/src/Service.php'), 'oldName');
        self::assertIsInt($start);
        $target = 'method:Demo\\Service::oldName';
        $this->planPath = $this->root . '/plan.json';
        file_put_contents($this->planPath, json_encode([
            'type' => 'method_rename_plan',
            'contract_version' => '1.0',
            'status' => 'safe',
            'target_id' => $target,
            'original_name' => 'oldName',
            'replacement_name' => 'newName',
            'family' => [$target],
            'provenance' => ['map_digest' => $map->mapDigest(), 'backend' => $map->backend, 'analysis_fingerprint' => $map->fingerprint?->toArray()],
            'edits' => [[
                'path' => 'src/Service.php', 'source_sha256' => $file->sha256,
                'start_file_pos' => $start, 'end_file_pos' => $start + 6, 'line_start' => 9, 'line_end' => 9,
                'expected' => 'oldName', 'replacement' => 'newName', 'role' => 'declaration', 'symbol_id' => $target, 'resolution' => 'parser_resolved',
            ]],
            'blind_spots' => [], 'stale_evidence' => [], 'blockers' => [], 'not_observable' => [],
        ], JSON_THROW_ON_ERROR));
        exec('cd ' . escapeshellarg($this->root) . ' && git init -q . && printf "map.json\\nplan.json\\n.agent-edit/\\n" > .gitignore && git add -A && git -c user.email=t@example.invalid -c user.name=t commit -qm init', $out, $exit);
        self::assertSame(0, $exit, implode("\n", $out));
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    public function testRemainingMentionsKeepAnOtherwisePassedVerificationIncomplete(): void
    {
        $result = $this->applyAndVerify('RES-OPEN');

        self::assertSame('incomplete', $result['status']);
        self::assertSame('passed', $result['checks']['target_absent'] ?? 'passed');
        self::assertSame('open', $result['residue']['status']);
        self::assertSame(2, $result['residue']['open']);
        self::assertSame(1, $result['residue']['historical']);
        self::assertSame(['README.md', 'templates/page.html.twig'], array_column($result['residue']['references'], 'path'));
        self::assertSame('incomplete', json_decode((string) file_get_contents($this->root . '/.agent-edit/receipts/RES-OPEN/' . EditEngine::VERIFICATION_FILE), true)['status']);
    }

    public function testAnAcceptedResidueRecordsItsReasonAndDoesNotBlock(): void
    {
        $result = $this->applyAndVerify('RES-ACCEPT', 'tracked in the docs follow-up');

        self::assertSame('passed', $result['status']);
        self::assertSame('accepted', $result['residue']['status']);
        self::assertSame('tracked in the docs follow-up', $result['residue']['disposition']);
        self::assertSame(2, $result['residue']['open']);
    }

    public function testFixingTheMentionsClearsTheResidue(): void
    {
        $engine = new EditEngine();
        $engine->applyWithReceipt($this->request('RES-FIX'));
        file_put_contents($this->root . '/README.md', "Call `Service::newName()` to start.\n");
        file_put_contents($this->root . '/templates/page.html.twig', "{{ service.newName() }}\n");
        $this->refreshMap();
        $result = $engine->verify($this->root, '.agent-edit/receipts/RES-FIX', $this->mapPath);

        self::assertSame('passed', $result['status']);
        self::assertSame('clear', $result['residue']['status']);
        self::assertSame(0, $result['residue']['open']);
        self::assertSame(1, $result['residue']['historical'], 'the changelog mention is history, not residue');
    }

    public function testPlanTypesWithoutADeclaredSymbolPassThroughUnchanged(): void
    {
        $result = ['status' => 'passed', 'checks' => []];

        self::assertSame($result, (new ResidueCheck())->apply($result, ['type' => 'property_removal_plan', 'target_id' => 'property:Demo\\Service::$x'], $this->root, null));
        self::assertSame($result, (new ResidueCheck())->apply($result, [], $this->root, null));
    }

    public function testTheCliExplainsOpenResidueExitsThreeAndAcceptsAReason(): void
    {
        (new EditEngine())->applyWithReceipt($this->request('RES-CLI'));
        $this->refreshMap();

        ob_start();
        $exit = (new CliApplication($this->root))->run(['agent-edit', 'verify', '--bundle=.agent-edit/receipts/RES-CLI', '--map-index=' . $this->mapPath]);
        $output = (string) ob_get_clean();

        self::assertSame(3, $exit);
        self::assertStringContainsString('verification: incomplete (residue_open: 2 non-historical', $output);
        self::assertStringContainsString('- residue: open (open 2, historical 1)', $output);
        self::assertStringContainsString('README.md:1 [class_member_qualified] Service::oldName', $output);

        ob_start();
        $accepted = (new CliApplication($this->root))->run(['agent-edit', 'verify', '--bundle=.agent-edit/receipts/RES-CLI', '--map-index=' . $this->mapPath, '--accept-residue=docs follow-up']);
        $acceptedOutput = (string) ob_get_clean();

        self::assertSame(0, $accepted);
        self::assertStringContainsString('- residue: accepted', $acceptedOutput);
    }

    /** @return array<string, mixed> */
    private function applyAndVerify(string $label, ?string $accept = null): array
    {
        $engine = new EditEngine();
        $engine->applyWithReceipt($this->request($label));
        $this->refreshMap();

        return $engine->verify($this->root, '.agent-edit/receipts/' . $label, $this->mapPath, '.', $accept);
    }

    private function refreshMap(): void
    {
        (new IndexWriter())->write(CachedAgentMapBuilder::build($this->root, ['src'], []), $this->mapPath);
    }

    private function request(string $label): ApplyRequest
    {
        return new ApplyRequest(
            repositoryRoot: $this->root,
            planPath: $this->planPath,
            mapIndexPath: $this->mapPath,
            mapRoot: $this->root,
            outputDirectory: $this->root . '/.agent-edit/receipts/' . $label,
            label: $label,
        );
    }
}
