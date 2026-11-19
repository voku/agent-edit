<?php

declare(strict_types=1);

namespace voku\AgentEdit\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentEdit\Receipt\PublicationJournal;
use voku\AgentEdit\Tests\Support\TemporaryProject;

/** `agent-edit recover` through the real binary: usage, argument errors, JSON output and exit codes. */
final class RecoverCommandTest extends TestCase
{
    private const SOURCE = "<?php\n\nnamespace App;\n\nfinal class Calc\n{\n}\n";

    private TemporaryProject $project;

    protected function setUp(): void
    {
        $this->project = new TemporaryProject(['src/Calc.php' => self::SOURCE]);
        mkdir($this->project->path('.agent-edit/receipts/fix'), 0o775, true);
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function testUsageAndArgumentErrors(): void
    {
        self::assertStringContainsString('agent-edit recover --bundle=PATH', $this->cli(['recover'])['stdout']);
        self::assertStringContainsString('conflict', $this->cli(['recover', '--help'])['stdout']);
        self::assertStringContainsString('recover --bundle=PATH', $this->cli(['help'])['stdout']);

        $unknown = $this->cli(['recover', '--nope']);
        self::assertSame(1, $unknown['exit']);
        self::assertStringContainsString('Unknown argument', $unknown['stderr']);
        self::assertSame(1, $this->cli(['recover', '--bundle='])['exit']);
        self::assertSame(1, $this->cli(['recover', '--bundle=.agent-edit/receipts/fix', '--format=xml'])['exit']);
        self::assertSame(1, $this->cli(['recover', '--bundle=.agent-edit/receipts/missing'])['exit']);
    }

    public function testNothingToRecoverIsSuccessAndJsonIsMachineReadable(): void
    {
        $run = $this->cli(['recover', '--bundle=.agent-edit/receipts/fix', '--format=json']);

        self::assertSame(0, $run['exit'], $run['stderr']);
        $result = json_decode($run['stdout'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('publication_recovery', $result['kind']);
        self::assertSame('nothing_to_recover', $result['status']);
    }

    public function testAConflictExitsNonZeroAndChangesNothing(): void
    {
        PublicationJournal::begin($this->project->path('.agent-edit/receipts/fix'), PublicationJournal::entriesFromPrepared([
            'files' => [$this->project->path('src/Calc.php') => "<?php\n// published\n"],
            'final_paths' => [$this->project->path('src/Calc.php') => $this->project->path('src/Calc.php')],
            'source_hashes' => [$this->project->path('src/Calc.php') => 'sha256:' . hash('sha256', self::SOURCE)],
        ], $this->project->root), ['receipt' => ['schema_version' => '1.0', 'task_id' => 'fix']]);
        $this->project->write('src/Calc.php', "<?php\n// someone else\n");

        $run = $this->cli(['recover', '--bundle=.agent-edit/receipts/fix']);

        self::assertSame(1, $run['exit']);
        self::assertStringContainsString('Recovery: conflict', $run['stdout']);
        self::assertStringContainsString('src/Calc.php: unexpected', $run['stdout']);
        self::assertStringContainsString('nothing was changed', $run['stderr']);
        self::assertSame("<?php\n// someone else\n", $this->project->read('src/Calc.php'));
        self::assertFileExists($this->project->path('.agent-edit/receipts/fix/' . PublicationJournal::FILE));
    }

    /**
     * @param list<string> $arguments
     * @return array{exit: int, stdout: string, stderr: string}
     */
    private function cli(array $arguments): array
    {
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__) . '/bin/agent-edit', ...$arguments],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->project->root,
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['exit' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
    }
}
