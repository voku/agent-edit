<?php

declare(strict_types=1);

namespace voku\AgentEdit\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentEdit\EditEngine;
use voku\AgentEdit\Failure\EditFailure;
use voku\AgentEdit\Failure\FailureCode;
use voku\AgentEdit\Receipt\ApplyRequest;
use voku\AgentEdit\Tests\Support\ScratchProject;

/**
 * Host policy for read-time digests: with `requireSourceHashes` a direct edit only lands on the bytes the caller based
 * it on. Without the policy, an anchor that still matches would publish over a file that changed after it was read.
 */
final class DirectEditSourceHashPolicyTest extends TestCase
{
    use ScratchProject;

    private const CALC = "<?php\n\nfinal class Calc\n{\n    public function add(int \$a, int \$b): int\n    {\n        return \$a - \$b;\n    }\n}\n";

    protected function setUp(): void
    {
        $this->createScratchProject('agent-edit-hash-policy-');
        $this->write('src/Calc.php', self::CALC);
    }

    protected function tearDown(): void
    {
        $this->removeScratchProject();
    }

    public function testWithoutThePolicyAnEditLandsOnBytesTheCallerNeverRead(): void
    {
        // The agent read CALC; someone else then adds a method. The anchor still matches, so the edit publishes.
        $this->write('src/Calc.php', str_replace("}\n}\n", "}\n\n    public function sub(): int { return 0; }\n}\n", self::CALC));
        $receipt = (new EditEngine())->applyWithReceipt($this->request($this->plan(null), false));

        self::assertSame('runner_succeeded', $receipt->status, 'this is the gap the policy closes');
    }

    public function testThePolicyRefusesAnEditWithoutADigestBeforeAnythingIsWritten(): void
    {
        try {
            (new EditEngine())->applyWithReceipt($this->request($this->plan(null), true));
            self::fail('An edit without expected_source_sha256 must be refused.');
        } catch (EditFailure $failure) {
            self::assertSame(FailureCode::SourceHashRequired, $failure->failureCode());
            self::assertSame(['missing' => [['edit' => 0, 'path' => 'src/Calc.php']]], $failure->details());
        }
        self::assertSame(self::CALC, $this->read('src/Calc.php'));
    }

    public function testThePolicyAcceptsTheReadDigestAndStillRefusesAChangedFile(): void
    {
        $digest = 'sha256:' . hash('sha256', self::CALC);
        $this->write('src/Other.php', self::CALC);
        $receipt = (new EditEngine())->applyWithReceipt($this->request($this->plan($digest), true));
        self::assertSame('runner_succeeded', $receipt->status);
        self::assertStringContainsString('return $a + $b;', $this->read('src/Calc.php'));

        $this->write('src/Other.php', self::CALC . "\n");
        try {
            (new EditEngine())->applyWithReceipt($this->request($this->plan($digest, 'src/Other.php'), true, 'other'));
            self::fail('A digest of other bytes must be refused.');
        } catch (EditFailure $failure) {
            self::assertSame(FailureCode::AnchorMissing, $failure->failureCode());
        }
    }

    public function testNewFilesNeedNoDigest(): void
    {
        $plan = ['type' => 'source_edit_plan', 'contract_version' => '1.0', 'target_id' => 'new', 'edits' => [], 'creates' => [['path' => 'src/New.php', 'content' => "<?php\n"]]];
        $receipt = (new EditEngine())->applyWithReceipt($this->request($plan, true));

        self::assertSame('runner_succeeded', $receipt->status);
        self::assertSame("<?php\n", $this->read('src/New.php'));
    }

    public function testTheCliFlagAndTheHostEnvironmentBothEnableThePolicy(): void
    {
        $flag = $this->cli(['edit', 'src/Calc.php', '--find=return $a - $b;', '--replace=return $a + $b;', '--require-source-hash', '--format=json'], []);
        self::assertSame(1, $flag['exit'], $flag['stdout'] . $flag['stderr']);
        self::assertSame('source_hash_required', $flag['json']['error']['code'] ?? null);
        self::assertSame('add_source_hash', $flag['json']['error']['next_action'] ?? null);

        $env = $this->cli(['edit', 'src/Calc.php', '--find=return $a - $b;', '--replace=return $a + $b;', '--format=json'], ['AGENT_EDIT_REQUIRE_SOURCE_HASH' => '1']);
        self::assertSame('source_hash_required', $env['json']['error']['code'] ?? null, $env['stdout'] . $env['stderr']);
        self::assertSame(self::CALC, $this->read('src/Calc.php'));

        $ok = $this->cli(['edit', 'src/Calc.php', '--find=return $a - $b;', '--replace=return $a + $b;', '--sha256=' . hash('sha256', self::CALC), '--format=json'], ['AGENT_EDIT_REQUIRE_SOURCE_HASH' => '1']);
        self::assertSame(0, $ok['exit'], $ok['stdout'] . $ok['stderr']);
        self::assertStringContainsString('return $a + $b;', $this->read('src/Calc.php'));
    }

    /** @return array<string, mixed> */
    private function plan(?string $digest, string $path = 'src/Calc.php'): array
    {
        $edit = ['path' => $path, 'find' => 'return $a - $b;', 'replace' => 'return $a + $b;'];
        if ($digest !== null) {
            $edit['expected_source_sha256'] = $digest;
        }

        return ['type' => 'source_edit_plan', 'contract_version' => '1.0', 'target_id' => 'fix', 'edits' => [$edit]];
    }

    /** @param array<string, mixed> $plan */
    private function request(array $plan, bool $require, string $label = 'fix'): ApplyRequest
    {
        $planPath = $this->root . '/plan-' . $label . '.json';
        file_put_contents($planPath, json_encode($plan, JSON_THROW_ON_ERROR));

        return new ApplyRequest(
            repositoryRoot: $this->root,
            planPath: $planPath,
            mapIndexPath: null,
            mapRoot: $this->root,
            outputDirectory: $this->root . '/.agent-edit/receipts/' . $label,
            label: $label,
            requireSourceHashes: $require,
        );
    }

    /**
     * @param list<string> $arguments
     * @param array<string, string> $env
     * @return array{exit: int, stdout: string, stderr: string, json: array<string, mixed>|null}
     */
    private function cli(array $arguments, array $env): array
    {
        $environment = getenv();
        unset($environment['AGENT_EDIT_REQUIRE_SOURCE_HASH']);
        $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/bin/agent-edit', ...$arguments], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->root, $env + $environment);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $json = json_decode($stdout, true);

        return ['exit' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr, 'json' => is_array($json) ? $json : null];
    }
}
