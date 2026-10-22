<?php

declare(strict_types=1);

namespace voku\AgentEdit\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentEdit\Cli\ApplyCommand;
use voku\AgentEdit\Cli\CliApplication;
use voku\AgentEdit\Cli\VerifyCommand;
use voku\AgentEdit\Tests\Support\CachedAgentMapBuilder;
use voku\AgentEdit\Tests\Support\ScratchProject;
use voku\AgentEdit\Tests\Support\UnifiedPatch;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\IndexWriter;

/** `--format=json` gives an agent one parseable document per call, on stdout, with the same exit codes as text mode. */
final class CliJsonTest extends TestCase
{
    use ScratchProject;

    private const SERVICE = "<?php\n\ndeclare(strict_types=1);\n\nnamespace Demo;\n\nfinal class Service\n{\n    public function oldName(): void\n    {\n    }\n}\n";

    private AgentMapIndex $map;

    protected function setUp(): void
    {
        $this->createScratchProject('agent-edit-cli-json-');
        $this->write('src/Service.php', self::SERVICE);
        $this->buildMap();
        file_put_contents($this->root . '/plan.json', json_encode($this->plan(), JSON_THROW_ON_ERROR));
    }

    protected function tearDown(): void
    {
        $this->removeScratchProject();
    }

    private function buildMap(): void
    {
        $this->map = CachedAgentMapBuilder::build($this->root, ['src'], []);
        mkdir($this->root . '/.agent-map', 0o775, true);
        (new IndexWriter())->write($this->map, $this->root . '/.agent-map/php-symbols.json');
    }

    /** @return array<string, mixed> */
    private function plan(): array
    {
        $start = (int) strpos(self::SERVICE, 'oldName');
        $file = $this->map->file('src/Service.php');
        self::assertNotNull($file);
        $target = 'method:Demo\\Service::oldName';

        return [
            'type' => 'method_rename_plan', 'contract_version' => '1.0', 'status' => 'safe', 'target_id' => $target,
            'original_name' => 'oldName', 'replacement_name' => 'newName', 'family' => [$target],
            'provenance' => ['map_digest' => $this->map->mapDigest(), 'backend' => $this->map->backend, 'analysis_fingerprint' => $this->map->fingerprint?->toArray()],
            'edits' => [['path' => 'src/Service.php', 'source_sha256' => $file->sha256, 'start_file_pos' => $start, 'end_file_pos' => $start + 6, 'line_start' => 9, 'line_end' => 9, 'expected' => 'oldName', 'replacement' => 'newName', 'role' => 'declaration', 'symbol_id' => $target, 'resolution' => 'parser_resolved']],
            'blind_spots' => [], 'stale_evidence' => [], 'blockers' => [], 'not_observable' => [],
        ];
    }

    /**
     * @param list<string> $tokens
     * @return array{int, string, array<string, mixed>|null}
     */
    private function cli(array $tokens): array
    {
        ob_start();
        $exit = (new CliApplication($this->root))->run(['agent-edit', ...$tokens]);
        $stdout = (string) ob_get_clean();

        return [$exit, $stdout, json_decode($stdout, true)];
    }

    public function testDryRunJsonCarriesTheBundleThePlannedChangesAndTheNextAction(): void
    {
        [$exit, , $json] = $this->cli(['apply', 'plan.json', '--dry-run', '--task=J1', '--format=json']);

        self::assertSame(0, $exit);
        self::assertIsArray($json);
        self::assertSame('1.0', $json['schema_version']);
        self::assertSame('apply', $json['command']);
        self::assertTrue($json['ok']);
        self::assertSame('prepared', $json['status']);
        self::assertTrue($json['dry_run']);
        self::assertSame(['type' => 'method_rename_plan', 'target_id' => 'method:Demo\\Service::oldName'], $json['plan']);
        self::assertSame($this->root . '/.agent-edit/receipts/J1', $json['bundle']);
        self::assertSame([['path' => 'src/Service.php', 'change' => 'modified', 'lines_added' => 1, 'lines_removed' => 1]], $json['planned_changes']);
        self::assertSame('apply', $json['next_action']);
        self::assertArrayNotHasKey('diff', $json, 'the diff is opt-in');
        self::assertArrayNotHasKey('changed_files', $json);
        self::assertSame(self::SERVICE, $this->read('src/Service.php'));
    }

    public function testDiffFlagAddsAUnifiedDiffThatReproducesTheRealResult(): void
    {
        [, , $preview] = $this->cli(['apply', 'plan.json', '--dry-run', '--task=J2', '--format=json', '--diff']);
        self::assertIsArray($preview);
        self::assertIsString($preview['diff']);

        $this->cli(['apply', 'plan.json', '--task=J3', '--format=json']);

        self::assertSame($this->read('src/Service.php'), UnifiedPatch::apply(self::SERVICE, $preview['diff']));
    }

    public function testRealApplyJsonReportsObservedChangedFilesAndToldVerifyNeedsAFreshMap(): void
    {
        [$exit, , $json] = $this->cli(['apply', 'plan.json', '--task=J4', '--format=json']);

        self::assertSame(0, $exit);
        self::assertIsArray($json);
        self::assertSame('runner_succeeded', $json['status']);
        self::assertFalse($json['dry_run']);
        self::assertSame(['src/Service.php'], $json['changed_files']);
        self::assertSame('refresh_map_then_verify', $json['next_action']);
        self::assertArrayNotHasKey('planned_changes', $json);
        self::assertStringContainsString('function newName()', $this->read('src/Service.php'));
    }

    public function testTextModeKeepsItsLinesAndOnlyAppendsWouldChangeForADryRun(): void
    {
        [$exit, $stdout] = $this->cli(['apply', 'plan.json', '--dry-run', '--task=T1']);

        self::assertSame(0, $exit);
        self::assertStringContainsString("- status: prepared\n", $stdout);
        self::assertStringContainsString("- would change: src/Service.php (modified, +1 -1)\n", $stdout);
        self::assertStringNotContainsString('--- a/', $stdout, 'no diff without --diff');

        [, $withDiff] = $this->cli(['apply', 'plan.json', '--dry-run', '--task=T2', '--diff']);
        self::assertStringContainsString("--- a/src/Service.php\n+++ b/src/Service.php\n", $withDiff);

        [, $real] = $this->cli(['apply', 'plan.json', '--task=T3']);
        self::assertStringNotContainsString('would change', $real, 'a real apply prints no preview lines');
    }

    /**
     * @dataProvider failureCases
     *
     * @param list<string> $tokens
     */
    public function testApplyFailuresAreOneJsonDocumentOnStdoutWithStableCodes(array $tokens, string $code, string $nextAction): void
    {
        file_put_contents($this->root . '/bad.json', '{broken');
        $blocked = $this->plan();
        $blocked['status'] = 'blocked';
        $blocked['blockers'] = ['called at src/X.php:1'];
        file_put_contents($this->root . '/blocked.json', json_encode($blocked, JSON_THROW_ON_ERROR));
        file_put_contents($this->root . '/copy.json', json_encode(['type' => 'interface_extract_plan', 'contract_version' => '1.0'], JSON_THROW_ON_ERROR));

        $run = $this->subprocess($tokens);
        $exit = $run['exit'];
        $stdout = $run['stdout'];
        $json = json_decode($stdout, true);
        self::assertIsArray($json, 'stdout must be exactly one JSON document: ' . $stdout);

        self::assertSame(1, $exit, 'the exit code is the one text mode always used');
        self::assertFalse($json['ok']);
        self::assertSame('apply', $json['command']);
        self::assertSame($code, $json['error']['code']);
        self::assertSame($nextAction, $json['error']['next_action']);
        self::assertNotSame('', $json['error']['message']);
        self::assertNotSame('', $json['error']['hint']);
        self::assertIsArray($json['error']['details'] ?? null);
        self::assertSame('', $run['stderr'], 'nothing is split onto stderr in json mode');
        self::assertSame(self::SERVICE, $this->read('src/Service.php'));
    }

    /** @return iterable<string, array{list<string>, string, string}> */
    public static function failureCases(): iterable
    {
        yield 'missing plan' => [['apply', 'nope.json', '--format=json'], 'input_not_found', 'provide_input'];
        yield 'missing map' => [['apply', 'plan.json', '--map-index=missing.json', '--format=json'], 'input_not_found', 'provide_input'];
        yield 'malformed plan' => [['apply', 'bad.json', '--format=json'], 'plan_malformed', 'replan'];
        yield 'unsupported plan' => [['apply', 'copy.json', '--format=json'], 'plan_unsupported', 'replan'];
        yield 'blocked plan' => [['apply', 'blocked.json', '--dry-run', '--format=json'], 'plan_refused', 'replan'];
        yield 'unknown option' => [['apply', 'plan.json', '--bogus=1', '--format=json'], 'usage', 'fix_arguments'];
        yield 'no plan' => [['apply', '--dry-run', '--format=json'], 'usage', 'fix_arguments'];
        yield 'bad task label' => [['apply', 'plan.json', '--task=../x', '--format=json'], 'usage', 'fix_arguments'];
        yield 'output outside root' => [['apply', 'plan.json', '--output-dir=../escape', '--format=json'], 'usage', 'fix_arguments'];
        yield 'diff without dry run' => [['apply', 'plan.json', '--diff', '--format=json'], 'usage', 'fix_arguments'];
        yield 'plan field given as an option' => [['apply', 'plan.json', '--allow-signature-change', '--format=json'], 'usage', 'fix_arguments'];
        yield 'unknown option without a value' => [['apply', 'plan.json', '--bogus', '--format=json'], 'usage', 'fix_arguments'];
        yield 'flag spelled with a space' => [['apply', 'plan.json', '--format', 'json', '--bogus', '1'], 'usage', 'fix_arguments'];
    }

    public function testAnInvalidFormatIsAUsageErrorInTextMode(): void
    {
        $run = $this->subprocess(['apply', 'plan.json', '--format=yaml']);

        self::assertSame(1, $run['exit']);
        self::assertSame('', $run['stdout']);
        self::assertStringContainsString('--format must be text or json', $run['stderr']);
    }

    public function testEditUsageErrorsHonourBothFormatSpellings(): void
    {
        // Regression: only `--format=json` was recognised before the plan existed; `--format json` printed a text error.
        foreach ([['--format=json'], ['--format', 'json']] as $format) {
            $run = $this->subprocess(['edit', 'src/Service.php', '--find', 'oldName', ...$format]);

            self::assertSame(2, $run['exit'], implode(' ', $format));
            self::assertSame('', $run['stderr'], implode(' ', $format));
            $json = json_decode($run['stdout'], true);
            self::assertIsArray($json, implode(' ', $format));
            self::assertFalse($json['ok']);
            self::assertSame('usage', $json['error']['code']);
        }
    }

    public function testTextModeErrorsStillGoToStderrWithTheOldPrefix(): void
    {
        $run = $this->subprocess(['apply', 'nope.json']);

        self::assertSame(1, $run['exit']);
        self::assertSame('', $run['stdout']);
        self::assertStringStartsWith('[ERROR] Plan file not found: nope.json', $run['stderr'], 'the doubled "refactor refactor" wording is gone');
    }

    public function testVerifyJsonPassesThroughTheResultAndTheExitCode(): void
    {
        $this->cli(['apply', 'plan.json', '--task=V1', '--format=json']);
        $this->buildMapFresh();

        [$exit, , $json] = $this->cli(['verify', '--bundle=.agent-edit/receipts/V1', '--format=json']);

        // Git-free evidence is scope_unproven, never passed (see the README): incomplete, exit 3.
        self::assertSame(3, $exit);
        self::assertIsArray($json);
        self::assertSame('verify', $json['command']);
        self::assertFalse($json['ok']);
        self::assertSame('incomplete', $json['status']);
        self::assertSame('scope_unproven', $json['reason']);
        self::assertSame('report', $json['next_action']);
        self::assertSame('rename_plan_verification', $json['result']['kind']);
        self::assertSame(['src/Service.php'], $json['result']['changed_files']);
        self::assertSame($json['result'], json_decode($this->read('.agent-edit/receipts/V1/verification-result.json'), true), 'the document is the persisted result');
    }

    public function testOpenMarkdownResidueIsNamedAsTheReasonAndAcceptingItMovesOnToTheNextOne(): void
    {
        $this->cli(['apply', 'plan.json', '--task=V4', '--format=json']);
        $this->write('README.md', "Call `Service::oldName()` to start.\n");
        $this->buildMapFresh();

        [$exit, , $open] = $this->cli(['verify', '--bundle=.agent-edit/receipts/V4', '--format=json']);
        self::assertSame(3, $exit);
        self::assertIsArray($open);
        self::assertSame('residue_open', $open['reason']);
        self::assertSame('fix_residue_then_reverify_or_accept_with_reason', $open['next_action']);
        self::assertSame('open', $open['result']['residue']['status']);
        self::assertSame('README.md', $open['result']['residue']['references'][0]['path']);

        [, $text] = $this->cli(['verify', '--bundle=.agent-edit/receipts/V4']);
        self::assertStringContainsString('(residue_open: 1 non-historical Markdown/template mention(s) of the old symbol remain)', $text);

        [, , $accepted] = $this->cli(['verify', '--bundle=.agent-edit/receipts/V4', '--accept-residue=docs follow in #12', '--format=json']);
        self::assertIsArray($accepted);
        self::assertSame('accepted', $accepted['result']['residue']['status']);
        self::assertSame('scope_unproven', $accepted['reason'], 'with the residue accepted only the Git-free scope limit is left');
    }

    public function testOrphanedImportsAreNamedAsTheReasonInBothFormats(): void
    {
        $this->cli(['apply', 'plan.json', '--task=V5', '--format=json']);
        $this->buildMapFresh();
        $stub = new class implements \voku\AgentEdit\Verify\BundleVerifier {
            public function verify(string $bundle, string $mapIndex, string $mapRoot): array
            {
                return [
                    'status' => 'incomplete',
                    'plan' => ['type' => 'method_rename_plan', 'contract_version' => '1.0', 'target_id' => 'method:Demo\\Service::oldName'],
                    'import_residue' => ['status' => 'open', 'open' => 2, 'imports' => [['path' => 'src/Service.php', 'line' => 6, 'import' => 'Demo\\Gone']]],
                ];
            }
        };
        $engine = new \voku\AgentEdit\EditEngine(verifierOverrides: [\voku\AgentEdit\Verify\EditMovePlanVerifier::class => $stub]);

        ob_start();
        $jsonExit = (new VerifyCommand($this->root, $engine))->run(['--bundle=.agent-edit/receipts/V5', '--format=json']);
        $json = json_decode((string) ob_get_clean(), true);
        ob_start();
        $textExit = (new VerifyCommand($this->root, $engine))->run(['--bundle=.agent-edit/receipts/V5']);
        $text = (string) ob_get_clean();

        self::assertSame(3, $jsonExit);
        self::assertSame(3, $textExit);
        self::assertIsArray($json);
        self::assertSame('import_residue_open', $json['reason']);
        self::assertSame('fix_residue_then_reverify_or_accept_with_reason', $json['next_action']);
        self::assertStringContainsString('(import_residue_open: 2 `use` import(s) orphaned by the deletion remain)', $text);
        self::assertStringContainsString('  - src/Service.php:6 use Demo\\Gone;', $text);
    }

    public function testVerifyJsonErrorsUseExitCodeTwoAndTheSameEnvelope(): void
    {
        [$exit, , $json] = $this->cli(['verify', '--bundle=.agent-edit/receipts/NOPE', '--format=json']);

        self::assertSame(2, $exit);
        self::assertIsArray($json);
        self::assertFalse($json['ok']);
        self::assertSame('input_not_found', $json['error']['code']);

        [$exit, , $usage] = $this->cli(['verify', '--wat=1', '--format=json']);
        self::assertSame(2, $exit);
        self::assertIsArray($usage);
        self::assertSame('usage', $usage['error']['code']);
    }

    public function testVerifyAgainstAStaleMapIsClassifiedSoAnAgentRefreshesItFirst(): void
    {
        $this->cli(['apply', 'plan.json', '--task=V2', '--format=json']);

        [$exit, , $json] = $this->cli(['verify', '--bundle=.agent-edit/receipts/V2', '--format=json']);

        self::assertSame(2, $exit);
        self::assertIsArray($json);
        self::assertSame('evidence_stale', $json['error']['code']);
        self::assertSame('refresh_map_and_replan', $json['error']['next_action']);
    }

    public function testVerifyTextOutputIsUnchanged(): void
    {
        $this->cli(['apply', 'plan.json', '--task=V3', '--format=json']);
        $this->buildMapFresh();

        [$exit, $stdout] = $this->cli(['verify', '--bundle=.agent-edit/receipts/V3']);

        self::assertSame(3, $exit);
        self::assertStringStartsWith('Refactor verification: incomplete (scope_unproven: only Map-indexed files were observed)', $stdout);
    }

    public function testHelpMentionsTheNewOptions(): void
    {
        ob_start();
        (new ApplyCommand($this->root))->run(['--help']);
        $apply = (string) ob_get_clean();
        ob_start();
        (new VerifyCommand($this->root))->run(['--help']);
        $verify = (string) ob_get_clean();
        ob_start();
        (new CliApplication($this->root))->run(['agent-edit', 'help']);
        $overview = (string) ob_get_clean();

        foreach ([$apply, $overview] as $text) {
            self::assertStringContainsString('--diff', $text);
            self::assertStringContainsString('--format', $text);
        }
        self::assertStringContainsString('--format=text|json', $verify);
    }

    private function buildMapFresh(): void
    {
        $this->map = CachedAgentMapBuilder::build($this->root, ['src'], []);
        (new IndexWriter())->write($this->map, $this->root . '/.agent-map/php-symbols.json');
    }

    /**
     * Runs the real binary so stdout, stderr and the exit code are observed separately, as an agent host sees them.
     *
     * @param list<string> $arguments
     * @return array{exit: int, stdout: string, stderr: string}
     */
    private function subprocess(array $arguments): array
    {
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__) . '/bin/agent-edit', ...$arguments],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->root,
        );
        self::assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['exit' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
    }
}
