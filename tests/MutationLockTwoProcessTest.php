<?php

declare(strict_types=1);

namespace voku\AgentEdit\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Real two-process proof for the project mutation lock: separate PHP processes, a shared on-disk log, no mocks.
 * The in-process seam of `MutationLock` cannot show that `flock` really serializes processes or that the OS drops
 * the lock when its holder dies.
 */
final class MutationLockTwoProcessTest extends TestCase
{
    private string $dir;

    /** @var list<resource> */
    private array $processes = [];

    protected function setUp(): void
    {
        $this->dir = (string) realpath(sys_get_temp_dir()) . '/agent-edit-lock-proof-' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/project-a', 0o775, true);
        mkdir($this->dir . '/project-b', 0o775, true);
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

    public function testASecondProcessEntersOnlyAfterTheFirstReleased(): void
    {
        $log = $this->dir . '/log.txt';
        $held = $this->dir . '/held-a';
        $a = $this->worker($this->dir . '/project-a', $log, 'A', 1500, $held);
        $this->waitForFile($held);
        $b = $this->worker($this->dir . '/project-a', $log, 'B', 50);

        self::assertSame(0, $this->finish($a));
        self::assertSame(0, $this->finish($b));
        self::assertSame(['attempt A', 'enter A', 'attempt B', 'exit A', 'enter B', 'exit B'], $this->lines($log));
    }

    public function testADifferentSpellingOfTheSameRootSharesTheLock(): void
    {
        symlink($this->dir . '/project-a', $this->dir . '/project-a-link');
        $log = $this->dir . '/log.txt';
        $held = $this->dir . '/held-a';
        $a = $this->worker($this->dir . '/project-a', $log, 'A', 1500, $held);
        $this->waitForFile($held);
        $b = $this->worker($this->dir . '/project-a-link', $log, 'B', 50);

        self::assertSame(0, $this->finish($a));
        self::assertSame(0, $this->finish($b));
        self::assertSame(['attempt A', 'enter A', 'attempt B', 'exit A', 'enter B', 'exit B'], $this->lines($log));
    }

    public function testDifferentProjectsDoNotBlockEachOther(): void
    {
        $log = $this->dir . '/log.txt';
        $held = $this->dir . '/held-a';
        $a = $this->worker($this->dir . '/project-a', $log, 'A', 1500, $held);
        $this->waitForFile($held);
        $b = $this->worker($this->dir . '/project-b', $log, 'B', 50);

        self::assertSame(0, $this->finish($b));
        self::assertSame(0, $this->finish($a));
        $lines = $this->lines($log);
        self::assertLessThan(array_search('exit A', $lines, true), array_search('exit B', $lines, true), 'project B finished while project A still held its own lock');
    }

    public function testAKilledHolderReleasesTheLockForTheWaitingProcess(): void
    {
        $log = $this->dir . '/log.txt';
        $held = $this->dir . '/held-a';
        $a = $this->worker($this->dir . '/project-a', $log, 'A', 60000, $held);
        $this->waitForFile($held);
        $b = $this->worker($this->dir . '/project-a', $log, 'B', 50);

        // B is queued behind A. SIGKILL gives A no chance to run any cleanup; the OS must drop its flock.
        $this->waitForLine($log, 'attempt B');
        usleep(300_000);
        self::assertNotContains('enter B', $this->lines($log), 'B must still be waiting while A is alive');
        proc_terminate($a, 9);

        self::assertSame(0, $this->finish($b));
        self::assertSame(['attempt A', 'enter A', 'attempt B', 'enter B', 'exit B'], $this->lines($log));
        proc_close($a);
    }

    /** @return resource */
    private function worker(string $root, string $log, string $name, int $holdMs, string $heldMarker = '')
    {
        $command = [PHP_BINARY, __DIR__ . '/Support/mutation-lock-worker.php', $root, $log, $name, (string) $holdMs];
        if ($heldMarker !== '') {
            $command[] = $heldMarker;
        }
        $process = proc_open($command, [1 => ['file', '/dev/null', 'w'], 2 => ['file', $this->dir . '/stderr-' . $name . '.txt', 'w']], $pipes);
        self::assertIsResource($process);
        $this->processes[] = $process;

        return $process;
    }

    /** @param resource $process */
    private function finish($process, int $timeoutSeconds = 15): int
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
        $deadline = microtime(true) + 10;
        while (!is_file($path)) {
            self::assertLessThan($deadline, microtime(true), 'timed out waiting for ' . $path);
            usleep(10_000);
        }
    }

    private function waitForLine(string $log, string $line): void
    {
        $deadline = microtime(true) + 10;
        while (!in_array($line, $this->lines($log), true)) {
            self::assertLessThan($deadline, microtime(true), 'timed out waiting for log line: ' . $line);
            usleep(10_000);
        }
    }

    /** @return list<string> */
    private function lines(string $log): array
    {
        $raw = is_file($log) ? (string) file_get_contents($log) : '';

        return $raw === '' ? [] : explode("\n", rtrim($raw, "\n"));
    }
}
