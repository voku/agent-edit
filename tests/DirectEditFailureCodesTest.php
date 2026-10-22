<?php

declare(strict_types=1);

namespace voku\AgentEdit\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentEdit\Tests\Support\ScratchProject;
use voku\AgentEdit\Tests\Support\UnifiedPatch;

/** The refusals an agent meets most often while editing text directly name their cause and the one legal next step. */
final class DirectEditFailureCodesTest extends TestCase
{
    use ScratchProject;

    private const CALC = "<?php\n\ndeclare(strict_types=1);\n\nfinal class Calc\n{\n    public function add(int \$a, int \$b): int\n    {\n        return \$a - \$b;\n    }\n\n    public function twice(int \$a): int\n    {\n        return \$a * 2;\n    }\n\n    public function again(int \$a): int\n    {\n        return \$a * 2;\n    }\n}\n";

    protected function setUp(): void
    {
        $this->createScratchProject('agent-edit-direct-codes-');
        $this->write('src/Calc.php', self::CALC);
    }

    protected function tearDown(): void
    {
        $this->removeScratchProject();
    }

    /**
     * @param list<string> $arguments
     * @return array{exit: int, stdout: string, stderr: string, json: array<string, mixed>|null}
     */
    private function cli(array $arguments, ?string $stdin = null): array
    {
        $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/bin/agent-edit', ...$arguments], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->root);
        self::assertIsResource($process);
        fwrite($pipes[0], $stdin ?? '');
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $json = json_decode($stdout, true);

        return ['exit' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr, 'json' => is_array($json) ? $json : null];
    }

    /**
     * @param list<string> $arguments
     * @return array<string, mixed>
     */
    private function error(array $arguments, ?string $stdin = null): array
    {
        $run = $this->cli($arguments, $stdin);
        self::assertSame(1, $run['exit'], $run['stdout'] . $run['stderr']);
        self::assertSame('', $run['stderr'], 'json mode keeps stderr empty');
        self::assertIsArray($run['json'], $run['stdout']);
        self::assertFalse($run['json']['ok']);
        self::assertSame(self::CALC, $this->read('src/Calc.php'), 'a refusal changes nothing');

        return $run['json']['error'];
    }

    public function testATextThatIsNotInTheFileIsAnchorMissingWithTheClosestLineAsAHint(): void
    {
        $error = $this->error(['edit', 'src/Calc.php', '--find=return $a + $b;', '--replace=x', '--format=json']);

        self::assertSame('anchor_missing', $error['code']);
        self::assertSame('reread_and_retry', $error['next_action']);
        self::assertSame(['path' => 'src/Calc.php', 'edit' => 0], $error['details']);
        self::assertStringContainsString('does not occur in src/Calc.php', $error['message']);
    }

    public function testATextThatOccursTwiceIsAnchorAmbiguousAndSaysHowMany(): void
    {
        $error = $this->error(['edit', 'src/Calc.php', '--find=return $a * 2;', '--replace=return $a * 3;', '--format=json']);

        self::assertSame('anchor_ambiguous', $error['code']);
        self::assertSame('disambiguate_anchor', $error['next_action']);
        self::assertSame(2, $error['details']['matches']);
        self::assertStringContainsString('occurrence', $error['hint']);
    }

    public function testAnOccurrenceBeyondTheMatchesIsAnchorMissing(): void
    {
        $error = $this->error(['edit', 'src/Calc.php', '--find=return $a * 2;', '--replace=x', '--occurrence=3', '--format=json']);

        self::assertSame('anchor_missing', $error['code']);
        self::assertStringContainsString('occurs only 2 time(s)', $error['message']);
    }

    public function testAFileThatChangedSinceItWasReadIsAnchorMissing(): void
    {
        $error = $this->error(['edit', 'src/Calc.php', '--find=return $a - $b;', '--replace=return $a + $b;', '--sha256=' . str_repeat('0', 64), '--format=json']);

        self::assertSame('anchor_missing', $error['code']);
        self::assertStringContainsString('changed since you read it', $error['message']);
    }

    public function testAMissingFileIsInputNotFound(): void
    {
        $error = $this->error(['edit', 'src/Nope.php', '--find=a', '--replace=b', '--format=json']);

        self::assertSame('input_not_found', $error['code']);
        self::assertSame(['path' => 'src/Nope.php'], $error['details']);
    }

    public function testChangingAPublicSignatureNeedsCallerEvidence(): void
    {
        $error = $this->error(['edit', 'src/Calc.php', '--find=public function add(int $a, int $b): int', '--replace=public function add(int $a, int $b, int $c = 0): int', '--format=json']);

        self::assertSame('api_change_refused', $error['code']);
        self::assertSame('use_map_plan_or_acknowledge', $error['next_action']);
        self::assertSame('src/Calc.php', $error['details']['path']);
        self::assertNotEmpty($error['details']['changes']);
        self::assertStringContainsString('allow_signature_change', $error['hint']);
    }

    public function testAcknowledgingTheSignatureChangeAppliesIt(): void
    {
        $run = $this->cli(['edit', 'src/Calc.php', '--find=public function add(int $a, int $b): int', '--replace=public function add(int $a, int $b, int $c = 0): int', '--allow-signature-change', '--format=json']);

        self::assertSame(0, $run['exit'], $run['stdout']);
        self::assertStringContainsString('int $c = 0', $this->read('src/Calc.php'));
    }

    public function testCreatingAFileThatExistsIsTargetExists(): void
    {
        $plan = json_encode(['type' => 'source_edit_plan', 'contract_version' => '1.0', 'target_id' => 'new', 'creates' => [['path' => 'src/Calc.php', 'content' => "<?php\n"]]], JSON_THROW_ON_ERROR);

        $error = $this->error(['apply', '-', '--format=json'], $plan);

        self::assertSame('target_exists', $error['code']);
        self::assertSame('edit_existing_file', $error['next_action']);
        self::assertSame(['path' => 'src/Calc.php'], $error['details']);
    }

    public function testTwoEditsOnTheSameTextOverlap(): void
    {
        $plan = json_encode(['type' => 'source_edit_plan', 'contract_version' => '1.0', 'target_id' => 'overlap', 'edits' => [
            ['path' => 'src/Calc.php', 'find' => 'return $a - $b;', 'replace' => 'return $a + $b;'],
            ['path' => 'src/Calc.php', 'find' => '$a - $b', 'replace' => '$b - $a'],
        ]], JSON_THROW_ON_ERROR);

        $error = $this->error(['apply', '-', '--format=json'], $plan);

        self::assertSame('edits_overlap', $error['code']);
        self::assertSame('merge_edits', $error['next_action']);
        self::assertSame('src/Calc.php', $error['details']['path']);
    }

    /**
     * @dataProvider malformedPlans
     *
     * @param array<string, mixed> $plan
     */
    public function testAPlanThatBreaksItsContractIsPlanMalformed(array $plan, string $fragment): void
    {
        $error = $this->error(['apply', '-', '--format=json'], json_encode($plan, JSON_THROW_ON_ERROR));

        self::assertSame('plan_malformed', $error['code']);
        self::assertStringContainsString($fragment, $error['message']);
        self::assertStringContainsString('agent-edit schema', $error['hint']);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function malformedPlans(): iterable
    {
        $base = ['type' => 'source_edit_plan', 'contract_version' => '1.0', 'target_id' => 'x'];
        yield 'no edits' => [$base + ['edits' => []], 'needs at least one edit or one create'];
        yield 'empty find' => [$base + ['edits' => [['path' => 'src/Calc.php', 'find' => '', 'replace' => 'x']]], 'non-empty string find'];
        yield 'no-op edit' => [$base + ['edits' => [['path' => 'src/Calc.php', 'find' => 'a', 'replace' => 'a']]], 'replaces text with itself'];
        yield 'unknown key' => [$base + ['edits' => [['path' => 'src/Calc.php', 'find' => 'a', 'replace' => 'b', 'bogus' => 1]]], 'unknown key(s) bogus'];
        yield 'parent traversal' => [$base + ['edits' => [['path' => '../x.php', 'find' => 'a', 'replace' => 'b']]], 'not a canonical relative path'];
        yield 'git directory' => [$base + ['edits' => [['path' => '.git/config', 'find' => 'a', 'replace' => 'b']]], 'which direct edits never touch'];
        yield 'both occurrence and all' => [$base + ['edits' => [['path' => 'src/Calc.php', 'find' => 'a', 'replace' => 'b', 'occurrence' => 1, 'all' => true]]], 'sets both occurrence and all'];
        yield 'missing target' => [['type' => 'source_edit_plan', 'contract_version' => '1.0', 'edits' => [['path' => 'src/Calc.php', 'find' => 'a', 'replace' => 'b']]], 'non-empty target_id'];
    }

    public function testAnUnsupportedContractVersionIsPlanUnsupportedNotMalformed(): void
    {
        $error = $this->error(['apply', '-', '--format=json'], json_encode(['type' => 'source_edit_plan', 'contract_version' => '2.0', 'target_id' => 'x', 'edits' => []], JSON_THROW_ON_ERROR));

        self::assertSame('plan_unsupported', $error['code']);
    }

    public function testDryRunOfADirectEditPreviewsTheDiffAndWritesNoSource(): void
    {
        $run = $this->cli(['edit', 'src/Calc.php', '--find=return $a - $b;', '--replace=return $a + $b;', '--dry-run', '--diff', '--format=json']);

        self::assertSame(0, $run['exit'], $run['stdout']);
        self::assertIsArray($run['json']);
        self::assertArrayHasKey('planned_changes', $run['json']);
        self::assertSame([['path' => 'src/Calc.php', 'change' => 'modified', 'lines_added' => 1, 'lines_removed' => 1]], $run['json']['planned_changes']);
        self::assertSame('apply', $run['json']['next_action']);
        self::assertSame(self::CALC, $this->read('src/Calc.php'));
        self::assertStringContainsString('return $a + $b;', UnifiedPatch::apply(self::CALC, $run['json']['diff']));
    }

    public function testDiffWithoutDryRunIsAUsageErrorAndWritesNothing(): void
    {
        $error = $this->error(['edit', 'src/Calc.php', '--find=return $a - $b;', '--replace=return $a + $b;', '--diff', '--format=json']);

        self::assertSame('usage', $error['code']);
        self::assertSame('fix_arguments', $error['next_action']);
        self::assertStringContainsString('--diff prints the preview of a --dry-run', $error['message']);
        self::assertSame(self::CALC, $this->read('src/Calc.php'));
    }

    public function testDryRunOfAPlanWithACreationShowsAWholeFileAddition(): void
    {
        $plan = json_encode(['type' => 'source_edit_plan', 'contract_version' => '1.0', 'target_id' => 'new', 'creates' => [['path' => 'tests/CalcTest.php', 'content' => "<?php\n\n// test\n"]]], JSON_THROW_ON_ERROR);

        $run = $this->cli(['apply', '-', '--dry-run', '--diff', '--format=json'], $plan);

        self::assertSame(0, $run['exit'], $run['stdout']);
        self::assertIsArray($run['json']);
        self::assertSame([['path' => 'tests/CalcTest.php', 'change' => 'created', 'lines_added' => 3, 'lines_removed' => 0]], $run['json']['planned_changes']);
        self::assertSame("<?php\n\n// test\n", UnifiedPatch::apply('', $run['json']['diff']));
        self::assertFileDoesNotExist($this->root . '/tests/CalcTest.php');
    }

    public function testARealDirectEditJsonPointsStraightAtVerifyBecauseNoMapIsNeeded(): void
    {
        $run = $this->cli(['edit', 'src/Calc.php', '--find=return $a - $b;', '--replace=return $a + $b;', '--task=E1', '--format=json']);

        self::assertSame(0, $run['exit'], $run['stdout']);
        self::assertIsArray($run['json']);
        self::assertSame('verify', $run['json']['next_action']);
        self::assertSame(['src/Calc.php'], $run['json']['changed_files']);
        self::assertSame('receipt_digests', $run['json']['changed_files_source'], 'without Git the source of the list is named, not implied');

        $verify = $this->cli(['verify', '--bundle=.agent-edit/receipts/E1', '--format=json']);
        self::assertIsArray($verify['json']);
        self::assertSame('source_edit_plan_verification', $verify['json']['result']['kind']);
    }

    public function testAnEditCommandUsageErrorIsJsonToo(): void
    {
        $run = $this->cli(['edit', '--format=json']);

        self::assertSame(2, $run['exit']);
        self::assertIsArray($run['json']);
        self::assertSame('usage', $run['json']['error']['code']);
        self::assertSame('fix_arguments', $run['json']['error']['next_action']);
        self::assertSame('', $run['stderr']);
    }
}
