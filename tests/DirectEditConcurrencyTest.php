<?php

declare(strict_types=1);

namespace voku\AgentEdit\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/** Real parallel `agent-edit edit` processes on one file: the mutation lock serializes them and anchors are re-proven. */
final class DirectEditConcurrencyTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-edit-race-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o775, true);
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

    public function testParallelEditsWithDistinctAnchorsAllLandAndTheReceiptsFormAChain(): void
    {
        $lines = [];
        for ($index = 0; $index < 8; ++$index) {
            $lines[] = '// m' . $index . ' pending';
        }
        file_put_contents($this->root . '/shared.txt', implode("\n", $lines) . "\n");
        $original = (string) file_get_contents($this->root . '/shared.txt');

        $results = $this->parallel(array_map(
            static fn (int $index): array => ['--find=// m' . $index . ' pending', '--replace=// m' . $index . ' done', '--task', 'r' . $index],
            range(0, 7),
        ));

        foreach ($results as $index => $result) {
            self::assertSame(0, $result['exit'], 'edit ' . $index . ': ' . $result['stderr']);
        }
        $final = (string) file_get_contents($this->root . '/shared.txt');
        self::assertSame(8, substr_count($final, ' done'), 'No concurrent edit may be lost.');
        self::assertStringNotContainsString('pending', $final);

        // Each receipt's "before" digest is some other receipt's "after" digest (or the original): a linear history.
        $befores = [];
        $afters = [];
        foreach (range(0, 7) as $index) {
            $execution = json_decode((string) file_get_contents($this->root . '/.agent-edit/receipts/r' . $index . '/execution.json'), true, 512, JSON_THROW_ON_ERROR);
            $befores[] = $execution['direct_edit']['files'][0]['before_sha256'];
            $afters[] = $execution['direct_edit']['files'][0]['after_sha256'];
        }
        self::assertCount(8, array_unique($afters));
        self::assertSame(hash('sha256', $original), array_values(array_diff($befores, $afters))[0], 'Exactly one edit saw the original file.');
        self::assertSame(hash('sha256', $final), array_values(array_diff($afters, $befores))[0], 'Exactly one edit produced the final file.');
    }

    public function testParallelEditsOfTheSameAnchorHaveExactlyOneWinner(): void
    {
        file_put_contents($this->root . '/one.txt', "// shared pending\n");

        $results = $this->parallel(array_map(
            static fn (int $index): array => ['--find=// shared pending', '--replace=// won by ' . $index, '--task', 'w' . $index, 'one.txt'],
            range(0, 5),
        ));

        $winners = array_filter($results, static fn (array $result): bool => $result['exit'] === 0);
        self::assertCount(1, $winners, 'Exactly one process may win the anchor.');
        foreach ($results as $result) {
            if ($result['exit'] !== 0) {
                self::assertStringContainsString('does not occur', $result['stderr']);
            }
        }
        self::assertSame(1, preg_match_all('/won by \d/', (string) file_get_contents($this->root . '/one.txt')));
    }

    /**
     * @param list<list<string>> $argumentSets edit options per process (a trailing FILE defaults to shared.txt)
     * @return list<array{exit: int, stdout: string, stderr: string}>
     */
    private function parallel(array $argumentSets): array
    {
        $processes = [];
        foreach ($argumentSets as $index => $arguments) {
            $file = in_array('one.txt', $arguments, true) ? [] : ['shared.txt'];
            $process = proc_open(
                [PHP_BINARY, dirname(__DIR__) . '/bin/agent-edit', 'edit', ...$file, ...$arguments],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                $this->root,
            );
            self::assertIsResource($process);
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);
            $processes[$index] = [$process, $pipes];
        }

        $results = [];
        $stdout = array_fill(0, count($processes), '');
        $stderr = array_fill(0, count($processes), '');
        $deadline = microtime(true) + 60;
        $running = $processes;
        while ($running !== [] && microtime(true) < $deadline) {
            foreach ($running as $index => [$process, $pipes]) {
                $stdout[$index] .= (string) stream_get_contents($pipes[1]);
                $stderr[$index] .= (string) stream_get_contents($pipes[2]);
                $status = proc_get_status($process);
                if (!$status['running']) {
                    $stdout[$index] .= (string) stream_get_contents($pipes[1]);
                    $stderr[$index] .= (string) stream_get_contents($pipes[2]);
                    $results[$index] = ['exit' => $status['exitcode'], 'stdout' => $stdout[$index], 'stderr' => $stderr[$index]];
                    fclose($pipes[1]);
                    fclose($pipes[2]);
                    proc_close($process);
                    unset($running[$index]);
                }
            }
            usleep(5000);
        }
        self::assertSame([], $running, 'Every parallel process must finish.');
        ksort($results);

        return array_values($results);
    }
}
