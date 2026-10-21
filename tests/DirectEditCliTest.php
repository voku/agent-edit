<?php

declare(strict_types=1);

namespace voku\AgentEdit\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class DirectEditCliTest extends TestCase
{
    private const SOURCE = "<?php\n\ndeclare(strict_types=1);\n\nnamespace Demo;\n\nfinal class Calc\n{\n    public function add(int \$a, int \$b): int\n    {\n        return \$a - \$b;\n    }\n\n    public function twice(int \$a): int\n    {\n        return \$a * 2;\n    }\n}\n";

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-edit-direct-cli-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0o775, true);
        file_put_contents($this->root . '/src/Calc.php', self::SOURCE);
        file_put_contents($this->root . '/README.md', "# Demo\n\nold name, old name\n");
    }

    protected function tearDown(): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->root);
    }

    public function testEditVerifyUndoRoundTripInAGitRepository(): void
    {
        $this->initGit();

        $edit = $this->cli(['edit', 'src/Calc.php', '--find=return $a - $b;', '--replace=return $a + $b;', '--task', 'fix']);
        self::assertSame(0, $edit['exit'], $edit['stderr']);
        self::assertStringContainsString('status: runner_succeeded', $edit['stdout']);
        self::assertStringContainsString('return $a + $b;', $this->read('src/Calc.php'));

        $verify = $this->cli(['verify', '--bundle=.agent-edit/receipts/fix']);
        self::assertSame(0, $verify['exit'], $verify['stderr']);
        self::assertStringContainsString('Direct edit verification: passed', $verify['stdout']);
        self::assertStringContainsString('plan: source_edit_plan@1.0', $verify['stdout']);

        $undo = $this->cli(['undo', '--bundle=.agent-edit/receipts/fix']);
        self::assertSame(0, $undo['exit'], $undo['stderr']);
        self::assertStringContainsString('restored src/Calc.php', $undo['stdout']);
        self::assertSame(self::SOURCE, $this->read('src/Calc.php'));

        $again = $this->cli(['undo', '--bundle=.agent-edit/receipts/fix']);
        self::assertSame(1, $again['exit']);
        self::assertStringContainsString('already undone', $again['stderr']);
    }

    public function testVerifyWithoutGitExitsThreeAndNamesTheMissingProof(): void
    {
        $this->cli(['edit', 'src/Calc.php', '--find=return $a - $b;', '--replace=return $a + $b;', '--task', 'fix']);

        $verify = $this->cli(['verify', '--bundle=.agent-edit/receipts/fix']);

        self::assertSame(3, $verify['exit']);
        self::assertStringContainsString('incomplete (scope_unproven: no Git working tree', $verify['stdout']);
    }

    public function testRefusedEditPrintsTheHintAndLeavesNoTraceAtAll(): void
    {
        $result = $this->cli(['edit', 'README.md', '--find=old name', '--replace=new name']);

        self::assertSame(1, $result['exit']);
        self::assertStringContainsString('occurs 2 times in README.md (lines 3, 3)', $result['stderr']);
        self::assertSame("# Demo\n\nold name, old name\n", $this->read('README.md'));
        self::assertDirectoryDoesNotExist($this->root . '/.agent-edit', 'A refused edit must not leave even empty receipt directories.');
    }

    public function testOccurrenceAndAllResolveAmbiguity(): void
    {
        self::assertSame(0, $this->cli(['edit', 'README.md', '--find=old name', '--replace=second', '--occurrence', '2', '--task', 'a'])['exit']);
        self::assertSame("# Demo\n\nold name, second\n", $this->read('README.md'));

        file_put_contents($this->root . '/README.md', "x x x\n");
        self::assertSame(0, $this->cli(['edit', 'README.md', '--find=x', '--replace=y', '--all', '--task', 'b'])['exit']);
        self::assertSame("y y y\n", $this->read('README.md'));
    }

    public function testEmptyReplacementDeletesInBothOptionForms(): void
    {
        self::assertSame(0, $this->cli(['edit', 'README.md', '--find=# Demo' . "\n\n", '--replace=', '--task', 'a'])['exit']);
        self::assertSame("old name, old name\n", $this->read('README.md'));

        self::assertSame(0, $this->cli(['edit', 'README.md', '--find', ', old name', '--replace', '', '--task', 'b'])['exit']);
        self::assertSame("old name\n", $this->read('README.md'));
    }

    public function testMultiLineTextComesFromFilesWithoutShellEscaping(): void
    {
        file_put_contents($this->root . '/find.txt', "    public function twice(int \$a): int\n    {\n        return \$a * 2;\n    }\n");
        file_put_contents($this->root . '/replace.txt', "    public function twice(int \$a): int\n    {\n        return \$a << 1;\n    }\n");

        $result = $this->cli(['edit', 'src/Calc.php', '--find-file=find.txt', '--replace-file=replace.txt', '--task', 'files']);

        self::assertSame(0, $result['exit'], $result['stderr']);
        self::assertStringContainsString('return $a << 1;', $this->read('src/Calc.php'));
    }

    public function testFindFilesAreUsedByteForByteIncludingTheTrailingNewline(): void
    {
        file_put_contents($this->root . '/eof.txt', 'no newline at end');
        file_put_contents($this->root . '/find.txt', "no newline at end\n");
        file_put_contents($this->root . '/replace.txt', "changed\n");

        $refused = $this->cli(['edit', 'eof.txt', '--find-file=find.txt', '--replace-file=replace.txt']);
        self::assertSame(1, $refused['exit']);
        self::assertStringContainsString('different whitespace', $refused['stderr']);
        self::assertSame('no newline at end', $this->read('eof.txt'));

        file_put_contents($this->root . '/find.txt', 'no newline at end');
        file_put_contents($this->root . '/replace.txt', 'changed');
        self::assertSame(0, $this->cli(['edit', 'eof.txt', '--find-file=find.txt', '--replace-file=replace.txt', '--task', 'eof'])['exit']);
        self::assertSame('changed', $this->read('eof.txt'));
    }

    public function testValuesStartingWithDashesAreAcceptedInTheSpaceSeparatedForm(): void
    {
        file_put_contents($this->root . '/README.md', "use --flag here\n");

        $result = $this->cli(['edit', 'README.md', '--find', '--flag', '--replace', '--other', '--task', 'dash']);

        self::assertSame(0, $result['exit'], $result['stderr']);
        self::assertSame("use --other here\n", $this->read('README.md'));
    }

    public function testUsageErrorsExitTwoAndChangeNothing(): void
    {
        $cases = [
            [['edit', 'README.md', '--find=a'], 'exactly one of --replace'],
            [['edit', 'README.md', '--replace=a'], 'exactly one of --find'],
            [['edit', 'README.md', '--find=a', '--find-file=x', '--replace=b'], 'exactly one of --find'],
            [['edit', '--find=a', '--replace=b'], 'requires the FILE'],
            [['edit', 'README.md', 'src/Calc.php', '--find=a', '--replace=b'], 'exactly one FILE'],
            [['edit', 'README.md', '--find=a', '--replace=b', '--occurrence', 'abc'], 'positive integer'],
            [['edit', 'README.md', '--find=a', '--replace=b', '--bogus', 'x'], 'Unknown option --bogus'],
            [['edit', 'README.md', '--find=a', '--find=b', '--replace=b'], 'Duplicate option --find'],
            [['edit', 'README.md', '--find-file=missing.txt', '--replace=b'], 'Unable to read --find-file'],
            [['edit', '/etc/hostname', '--find=a', '--replace=b'], 'inside the project root'],
        ];
        foreach ($cases as [$arguments, $message]) {
            $result = $this->cli($arguments);
            self::assertSame(2, $result['exit'], implode(' ', $arguments) . ': ' . $result['stderr']);
            self::assertStringContainsString($message, $result['stderr'], implode(' ', $arguments));
        }

        self::assertSame("# Demo\n\nold name, old name\n", $this->read('README.md'));
        self::assertDirectoryDoesNotExist($this->root . '/.agent-edit');
    }

    public function testAbsolutePathInsideTheProjectIsAccepted(): void
    {
        $result = $this->cli(['edit', (string) realpath($this->root) . '/README.md', '--find=# Demo', '--replace=# Renamed', '--task', 'abs']);

        self::assertSame(0, $result['exit'], $result['stderr']);
        self::assertStringStartsWith('# Renamed', $this->read('README.md'));
    }

    public function testStaleDigestOptionRefusesTheEdit(): void
    {
        $result = $this->cli(['edit', 'README.md', '--find=# Demo', '--replace=# X', '--sha256', str_repeat('0', 64)]);

        self::assertSame(1, $result['exit']);
        self::assertStringContainsString('changed since you read it', $result['stderr']);
    }

    public function testMatchingDigestOptionAcceptsBothPrefixedAndBareForms(): void
    {
        $digest = hash('sha256', $this->read('README.md'));

        self::assertSame(0, $this->cli(['edit', 'README.md', '--find=# Demo', '--replace=# X', '--sha256', 'sha256:' . $digest, '--task', 'a'])['exit']);

        $digest = hash('sha256', $this->read('README.md'));
        self::assertSame(0, $this->cli(['edit', 'README.md', '--find=# X', '--replace=# Y', '--sha256', $digest, '--task', 'b'])['exit']);
    }

    public function testApiChangeIsRefusedUnlessAcknowledged(): void
    {
        $arguments = ['edit', 'src/Calc.php', '--find=public function twice(int $a): int', '--replace=public function twice(int $a, int $f = 2): int'];

        $refused = $this->cli($arguments);
        self::assertSame(1, $refused['exit']);
        self::assertStringContainsString('allow_signature_change', $refused['stderr']);
        self::assertSame(self::SOURCE, $this->read('src/Calc.php'));

        $allowed = $this->cli([...$arguments, '--allow-signature-change', '--task', 'api']);
        self::assertSame(0, $allowed['exit'], $allowed['stderr']);
        self::assertStringContainsString('int $f = 2', $this->read('src/Calc.php'));
        $execution = json_decode($this->read('.agent-edit/receipts/api/execution.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertStringContainsString('Demo\Calc::twice', $execution['direct_edit']['acknowledged_api_changes'][0]);
    }

    public function testDryRunValidatesWithoutWriting(): void
    {
        $result = $this->cli(['edit', 'src/Calc.php', '--find=return $a - $b;', '--replace=return $a + $b;', '--dry-run', '--task', 'dry']);

        self::assertSame(0, $result['exit'], $result['stderr']);
        self::assertStringContainsString('status: prepared', $result['stdout']);
        self::assertSame(self::SOURCE, $this->read('src/Calc.php'));
    }

    public function testCustomOutputDirAndTargetLabelAreHonoured(): void
    {
        $result = $this->cli(['edit', 'README.md', '--find=# Demo', '--replace=# X', '--output-dir=out/bundle', '--target=docs-title']);

        self::assertSame(0, $result['exit'], $result['stderr']);
        self::assertStringContainsString('- target: docs-title', $result['stdout']);
        self::assertFileExists($this->root . '/out/bundle/execution.json');
        self::assertFileExists($this->root . '/out/bundle/plan.json');
    }

    public function testOutputDirOutsideTheProjectIsRefused(): void
    {
        $result = $this->cli(['edit', 'README.md', '--find=# Demo', '--replace=# X', '--output-dir=/tmp/agent-edit-elsewhere']);

        self::assertSame(1, $result['exit']);
        self::assertStringContainsString('must stay inside the project root', $result['stderr']);
        self::assertStringStartsWith('# Demo', $this->read('README.md'));
    }

    public function testInvalidTaskLabelIsRefused(): void
    {
        $result = $this->cli(['edit', 'README.md', '--find=# Demo', '--replace=# X', '--task', '../evil']);

        self::assertSame(1, $result['exit']);
        self::assertStringContainsString('valid --task label', $result['stderr']);
    }

    public function testApplyReadsAMultiFilePlanWithCreatesFromStdin(): void
    {
        $this->initGit();
        $plan = json_encode([
            'type' => 'source_edit_plan',
            'contract_version' => '1.0',
            'target_id' => 'add-test',
            'edits' => [['path' => 'src/Calc.php', 'find' => 'return $a * 2;', 'replace' => 'return $a << 1;']],
            'creates' => [['path' => 'tests/CalcTest.php', 'content' => "<?php\n\nfinal class CalcTest {}\n"]],
        ], JSON_THROW_ON_ERROR);

        $apply = $this->cli(['apply', '-', '--task', 'multi'], $plan);
        self::assertSame(0, $apply['exit'], $apply['stderr']);
        self::assertFileExists($this->root . '/tests/CalcTest.php');
        self::assertFileExists($this->root . '/.agent-edit/receipts/multi/plan.json');

        $verify = $this->cli(['verify', '--bundle=.agent-edit/receipts/multi']);
        self::assertSame(0, $verify['exit'], $verify['stderr']);

        self::assertSame(0, $this->cli(['undo', '--bundle=.agent-edit/receipts/multi'])['exit']);
        self::assertFileDoesNotExist($this->root . '/tests/CalcTest.php');
        self::assertDirectoryDoesNotExist($this->root . '/tests');
    }

    public function testApplyFromStdinRefusalLeavesNoStagedPlanOrDirectories(): void
    {
        $plan = json_encode(['type' => 'source_edit_plan', 'contract_version' => '1.0', 'target_id' => 'x', 'edits' => [['path' => 'README.md', 'find' => 'nope', 'replace' => 'y']]], JSON_THROW_ON_ERROR);

        $result = $this->cli(['apply', '-'], $plan);

        self::assertSame(1, $result['exit']);
        self::assertStringContainsString('does not occur', $result['stderr']);
        self::assertDirectoryDoesNotExist($this->root . '/.agent-edit');
    }

    public function testApplyFromEmptyStdinIsRefused(): void
    {
        $result = $this->cli(['apply', '-'], '  ');

        self::assertSame(1, $result['exit']);
        self::assertStringContainsString('empty plan on stdin', $result['stderr']);
    }

    public function testApplyOfAPlanFileNeedsNoMapIndex(): void
    {
        file_put_contents($this->root . '/plan.json', json_encode([
            'type' => 'source_edit_plan',
            'contract_version' => '1.0',
            'target_id' => 'file-plan',
            'edits' => [['path' => 'README.md', 'find' => '# Demo', 'replace' => '# Planned']],
        ], JSON_THROW_ON_ERROR));

        $result = $this->cli(['apply', 'plan.json', '--task', 'filed']);

        self::assertSame(0, $result['exit'], $result['stderr']);
        self::assertStringStartsWith('# Planned', $this->read('README.md'));
        self::assertFileDoesNotExist($this->root . '/.agent-map/php-symbols.json');
    }

    public function testMapPlansStillRequireAMapIndexFile(): void
    {
        file_put_contents($this->root . '/plan.json', json_encode(['type' => 'method_removal_plan', 'contract_version' => '1.0'], JSON_THROW_ON_ERROR));

        $result = $this->cli(['apply', 'plan.json']);

        self::assertSame(1, $result['exit']);
        self::assertStringContainsString('map index not found', $result['stderr']);
    }

    public function testSchemaPrintsTheContractAndRejectsUnknownNames(): void
    {
        $schema = $this->cli(['schema']);
        self::assertSame(0, $schema['exit']);
        $decoded = json_decode($schema['stdout'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('agent-edit source_edit_plan@1.0', $decoded['title']);
        self::assertSame($decoded, json_decode($this->cli(['schema', 'source_edit_plan'])['stdout'], true, 512, JSON_THROW_ON_ERROR));

        $unknown = $this->cli(['schema', 'method_move_plan']);
        self::assertSame(2, $unknown['exit']);
        self::assertStringContainsString('Usage: agent-edit schema', $unknown['stderr']);
    }

    public function testCapabilitiesAdvertiseTheDirectEditWithoutAMap(): void
    {
        $result = $this->cli(['capabilities', '--format=json']);

        $decoded = json_decode($result['stdout'], true, 512, JSON_THROW_ON_ERROR);
        $direct = array_values(array_filter($decoded['capabilities'], static fn (array $row): bool => $row['plan_type'] === 'source_edit_plan'));
        self::assertCount(1, $direct);
        self::assertFalse($direct[0]['requires_map']);
        self::assertSame('source-edit-plan', $direct[0]['runner']);
    }

    public function testUndoUsageAndMissingBundle(): void
    {
        self::assertSame(0, $this->cli(['undo'])['exit']);
        self::assertStringContainsString('Usage:', $this->cli(['undo', '--help'])['stdout']);

        $unknown = $this->cli(['undo', '--nope']);
        self::assertSame(1, $unknown['exit']);
        self::assertStringContainsString('Unknown argument', $unknown['stderr']);

        $missing = $this->cli(['undo', '--bundle=.agent-edit/receipts/none']);
        self::assertSame(1, $missing['exit']);
        self::assertStringContainsString('bundle not found', $missing['stderr']);

        $empty = $this->cli(['undo', '--bundle=']);
        self::assertSame(1, $empty['exit']);
        self::assertStringContainsString('requires --bundle', $empty['stderr']);
    }

    public function testEditHelpAndTopLevelHelpDocumentTheNewCommands(): void
    {
        $help = $this->cli(['edit', '--help']);
        self::assertSame(0, $help['exit']);
        self::assertStringContainsString('--allow-signature-change', $help['stdout']);
        self::assertStringContainsString('agent-edit undo', $help['stdout']);

        $top = $this->cli(['help']);
        foreach (['agent-edit edit FILE', 'agent-edit undo --bundle=PATH', 'agent-edit schema', 'apply PLAN|-'] as $needle) {
            self::assertStringContainsString($needle, $top['stdout']);
        }
    }

    /**
     * @param list<string> $arguments
     * @return array{exit: int, stdout: string, stderr: string}
     */
    private function cli(array $arguments, string $stdin = ''): array
    {
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__) . '/bin/agent-edit', ...$arguments],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->root,
        );
        self::assertIsResource($process);
        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['exit' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
    }

    private function initGit(): void
    {
        $run = fn (string $command) => shell_exec('cd ' . escapeshellarg($this->root) . ' && ' . $command . ' 2>&1');
        $run('git init -q');
        $run('git config user.email test@example.invalid');
        $run('git config user.name test');
        file_put_contents($this->root . '/.gitignore', ".agent-edit/\n");
        $run('git add -A && git commit -qm init');
    }

    private function read(string $relative): string
    {
        return (string) file_get_contents($this->root . '/' . $relative);
    }
}
