<?php

declare(strict_types=1);

namespace voku\AgentEdit\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Two real processes apply into the same receipt bundle. The first one claims the bundle and is still inside host
 * authorization when the second one starts. Without a per-bundle lock the second run claims the same bundle, applies
 * the edit and records success, after which the first run fails on the changed source and overwrites that successful
 * receipt with a failure: the source stays mutated while its only evidence says nothing happened.
 */
final class ReceiptBundleTwoProcessTest extends TestCase
{
    private const SOURCE = "<?php\n\nnamespace Demo;\n\nfinal class Calc\n{\n    public function add(int \$a, int \$b): int\n    {\n        return \$a - \$b;\n    }\n}\n";

    private string $dir;

    /** @var list<resource> */
    private array $processes = [];

    protected function setUp(): void
    {
        $this->dir = (string) realpath(sys_get_temp_dir()) . '/agent-edit-bundle-proof-' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/project/src', 0o775, true);
        file_put_contents($this->dir . '/project/src/Calc.php', self::SOURCE);
        $git = 'cd ' . escapeshellarg($this->dir . '/project') . ' && ';
        exec($git . 'git init -q && git config user.email test@example.invalid && git config user.name test');
        file_put_contents($this->dir . '/project/.gitignore', ".agent-edit/\n");
        exec($git . 'git add -A && git commit -qm init');
        file_put_contents($this->dir . '/plan.json', json_encode([
            'type' => 'source_edit_plan',
            'contract_version' => '1.0',
            'target_id' => 'fix-add',
            'edits' => [['path' => 'src/Calc.php', 'find' => 'return $a - $b;', 'replace' => 'return $a + $b;']],
        ], JSON_THROW_ON_ERROR));
    }

    protected function tearDown(): void
    {
        foreach ($this->processes as $process) {
            if (is_resource($process)) {
                proc_terminate($process, 9);
                proc_close($process);
            }
        }
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    public function testASecondRunOnTheSameBundleCannotOverwriteTheFirstRunsReceipt(): void
    {
        $bundle = $this->dir . '/project/.agent-edit/receipts/shared';
        $held = $this->dir . '/held-a';
        $release = $this->dir . '/release-a';

        $a = $this->worker('A', $bundle, $held, $release);
        $this->waitForFile($held);
        // Independent of when B gets scheduled: while A sits between claim and receipt, its bundle lock is held.
        self::assertFalse($this->bundleLockIsFree($bundle), 'A must hold the bundle lock from claim until its receipt is written');
        $b = $this->worker('B', $bundle);
        usleep(500_000); // B gets every chance to run inside the window A left open
        touch($release);

        $exitA = $this->finish($a);
        $exitB = $this->finish($b);

        $receipt = json_decode((string) file_get_contents($bundle . '/execution.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('runner_succeeded', $receipt['status'], 'the bundle must keep the receipt of the run that really edited');
        self::assertArrayHasKey('direct_edit', $receipt);
        self::assertSame(0, $exitA, (string) @file_get_contents($this->dir . '/stderr-A.txt'));
        self::assertSame(2, $exitB);
        self::assertStringContainsString('already records an applied plan', (string) file_get_contents($this->dir . '/stderr-B.txt'));
        self::assertStringContainsString('return $a + $b;', (string) file_get_contents($this->dir . '/project/src/Calc.php'));
    }

    /** Non-blocking probe of the lock EditEngine takes for `$bundle`; the probe is always released again. */
    private function bundleLockIsFree(string $bundle): bool
    {
        $path = sys_get_temp_dir() . '/agent-edit-bundle-' . hash('sha256', (string) realpath($bundle)) . '.lock';
        $handle = fopen($path, 'c');
        self::assertIsResource($handle);
        try {
            $acquired = flock($handle, LOCK_EX | LOCK_NB);
            if ($acquired) {
                flock($handle, LOCK_UN);
            }

            return $acquired;
        } finally {
            fclose($handle);
        }
    }

    /** @return resource */
    private function worker(string $name, string $bundle, ?string $held = null, ?string $release = null)
    {
        $command = [PHP_BINARY, __DIR__ . '/Support/bundle-reuse-worker.php', $this->dir . '/project', $this->dir . '/plan.json', $bundle];
        if ($held !== null && $release !== null) {
            array_push($command, $held, $release);
        }
        $process = proc_open($command, [1 => ['file', '/dev/null', 'w'], 2 => ['file', $this->dir . '/stderr-' . $name . '.txt', 'w']], $pipes);
        self::assertIsResource($process);
        $this->processes[] = $process;

        return $process;
    }

    /** @param resource $process */
    private function finish($process, int $timeoutSeconds = 30): int
    {
        $deadline = microtime(true) + $timeoutSeconds;
        do {
            $status = proc_get_status($process);
            if (!$status['running']) {
                return $status['exitcode'];
            }
            usleep(20_000);
        } while (microtime(true) < $deadline);

        self::fail('worker did not finish within ' . $timeoutSeconds . 's');
    }

    private function waitForFile(string $path): void
    {
        $deadline = microtime(true) + 15;
        while (!is_file($path)) {
            self::assertLessThan($deadline, microtime(true), 'timed out waiting for ' . $path);
            usleep(10_000);
        }
    }
}
