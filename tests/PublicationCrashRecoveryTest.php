<?php

declare(strict_types=1);

namespace voku\AgentEdit\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentEdit\EditEngine;
use voku\AgentEdit\Receipt\PublicationJournal;
use voku\AgentEdit\Tests\Support\CachedAgentMapBuilder;
use voku\AgentEdit\Tests\Support\TemporaryProject;
use voku\AgentMap\Index\IndexWriter;
use voku\AgentMap\Rename\MethodRenamePlanner;

/**
 * Real process kills during publication: a child process applies a plan through the real engine and publishers and is
 * SIGKILLed at an exact rename, so no rollback, no cleanup and no receipt runs. Recovery must then reach a consistent,
 * evidence-backed state from the bundle alone.
 */
final class PublicationCrashRecoveryTest extends TestCase
{
    private const A = "<?php\n\nnamespace App;\n\nfinal class A\n{\n    public function value(): int\n    {\n        return 1;\n    }\n}\n";
    private const B = "<?php\n\nnamespace App;\n\nfinal class B\n{\n    public function value(): int\n    {\n        return 2;\n    }\n}\n";
    private const NOTES = "unrelated work in progress\n";

    private TemporaryProject $project;

    /** Scratch directory outside the project for the kill marker and worker stderr (never observed by git). */
    private string $aux;

    /** @var list<resource> */
    private array $processes = [];

    protected function setUp(): void
    {
        $this->project = new TemporaryProject([
            'src/A.php' => self::A,
            'src/B.php' => self::B,
            'notes.txt' => "committed\n",
            'src/Greeter.php' => "<?php\n\nnamespace App;\n\nfinal class Greeter\n{\n    public function greet(): string\n    {\n        return 'hi';\n    }\n}\n",
            'src/Runner.php' => "<?php\n\nnamespace App;\n\nfinal class Runner\n{\n    public function run(Greeter \$greeter): string\n    {\n        return \$greeter->greet();\n    }\n}\n",
        ]);
        $root = escapeshellarg($this->project->root);
        exec("git -C {$root} init -q && git -C {$root} add -A && git -C {$root} -c user.email=t@t -c user.name=t commit -qm init", $output, $exit);
        self::assertSame(0, $exit, implode("\n", $output));
        // Uncommitted work that recovery must neither touch nor attribute to the plan.
        $this->project->write('notes.txt', self::NOTES);
        $this->aux = sys_get_temp_dir() . '/agent-edit-crash-aux-' . bin2hex(random_bytes(6));
        mkdir($this->aux);
    }

    protected function tearDown(): void
    {
        foreach ($this->processes as $process) {
            if (is_resource($process)) {
                proc_terminate($process, 9);
                proc_close($process);
            }
        }
        $this->project->remove();
        exec('rm -rf ' . escapeshellarg($this->aux));
    }

    public function testKilledMidPublicationIsRolledBackFromTheBundle(): void
    {
        $this->writeDirectPlan();
        // Renames: A published (1), B published (2), C created (3). Killed after the first.
        $this->runAndKill('direct', 1);
        $bundle = $this->bundle();

        self::assertFileExists($bundle . '/' . PublicationJournal::FILE);
        self::assertFileDoesNotExist($bundle . '/' . EditEngine::RECEIPT_FILE, 'the process died before any receipt');
        self::assertStringContainsString('return 10;', $this->project->read('src/A.php'), 'A was published before the kill');
        self::assertSame(self::B, $this->project->read('src/B.php'));
        self::assertNotSame([], $this->temporaryFiles(), 'the killed publisher left stage/backup files behind');

        $result = (new EditEngine())->recover($this->project->root, '.agent-edit/receipts/crash');

        self::assertSame('rolled_back', $result['status']);
        self::assertSame(self::A, $this->project->read('src/A.php'));
        self::assertSame(self::B, $this->project->read('src/B.php'));
        self::assertFileDoesNotExist($this->project->path('src/C.php'));
        self::assertSame([], $this->temporaryFiles());
        self::assertSame(self::NOTES, $this->project->read('notes.txt'));
        self::assertFileDoesNotExist($bundle . '/' . PublicationJournal::FILE);
        $receipt = $this->receipt();
        self::assertSame('runner_failed', $receipt['status']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $receipt['recovery']['attempt']);
        self::assertSame(['source' => PublicationJournal::FILE, 'state' => 'interrupted', 'rolled_back' => true], array_diff_key($receipt['recovery'], ['attempt' => true]));
        self::assertSame([], $receipt['changed_files'], 'after the rollback git observes no change by this plan');

        // The bundle is consistent again: a fresh attempt may reuse it, and recovering twice changes nothing.
        self::assertSame('nothing_to_recover', (new EditEngine())->recover($this->project->root, '.agent-edit/receipts/crash')['status']);
        self::assertSame(self::A, $this->project->read('src/A.php'));
    }

    public function testKilledAfterPublicationBeforeTheReceiptRecordsTheVerifiedPublication(): void
    {
        $this->writeDirectPlan();
        $this->runAndKill('direct', 3);
        $bundle = $this->bundle();
        self::assertFileDoesNotExist($bundle . '/' . EditEngine::RECEIPT_FILE);

        $result = (new EditEngine())->recover($this->project->root, '.agent-edit/receipts/crash');

        self::assertSame('published', $result['status']);
        self::assertSame([], $this->temporaryFiles());
        $receipt = $this->receipt();
        self::assertSame('runner_succeeded', $receipt['status']);
        self::assertSame('published', $receipt['recovery']['state']);
        // Observed by git after the fact, exactly like a normal run: the unrelated uncommitted change is not included.
        self::assertSame('git_status_diff', $receipt['changed_files_source']);
        self::assertSame(['src/A.php', 'src/B.php', 'src/C.php'], $receipt['changed_files']);
        self::assertSame(self::NOTES, $this->project->read('notes.txt'));

        // Existing consumers work on the recovered bundle: verify decides independently, undo restores the pre-images.
        self::assertSame('passed', (new EditEngine())->verify($this->project->root, '.agent-edit/receipts/crash')['status']);
        (new EditEngine())->undo($this->project->root, '.agent-edit/receipts/crash');
        self::assertSame(self::A, $this->project->read('src/A.php'));
        self::assertSame(self::B, $this->project->read('src/B.php'));
        self::assertFileDoesNotExist($this->project->path('src/C.php'));
    }

    public function testAnExternalChangeBeforeRecoveryIsAConflictThatTouchesNothing(): void
    {
        $this->writeDirectPlan();
        $this->runAndKill('direct', 1);
        $this->project->write('src/B.php', "<?php\n// edited by someone else\n");
        $before = $this->snapshot();

        $result = (new EditEngine())->recover($this->project->root, '.agent-edit/receipts/crash');

        self::assertSame('conflict', $result['status']);
        self::assertContains(['path' => 'src/B.php', 'state' => PublicationJournal::STATE_UNEXPECTED], $result['files']);
        self::assertSame($before, $this->snapshot(), 'a conflict changes no file, not even temporary ones');
        self::assertFileExists($this->bundle() . '/' . PublicationJournal::FILE, 'the journal stays for a later retry');

        // Once the conflicting file is put back, recovery proceeds.
        $this->project->write('src/B.php', self::B);
        self::assertSame('rolled_back', (new EditEngine())->recover($this->project->root, '.agent-edit/receipts/crash')['status']);
        self::assertSame(self::A, $this->project->read('src/A.php'));
    }

    public function testAPendingJournalBlocksEveryOtherBundleConsumer(): void
    {
        $this->writeDirectPlan();
        $this->runAndKill('direct', 3);

        foreach ([
            'apply' => fn () => $this->runChild('direct', 0),
            'verify' => fn () => (new EditEngine())->verify($this->project->root, '.agent-edit/receipts/crash'),
            'undo' => fn () => (new EditEngine())->undo($this->project->root, '.agent-edit/receipts/crash'),
        ] as $consumer => $call) {
            try {
                $outcome = $call();
                if ($consumer === 'apply') {
                    self::assertNotSame(0, $outcome['exit'], 'apply must refuse a bundle with a pending journal');
                    self::assertStringContainsString('agent-edit recover', $outcome['stderr']);
                    continue;
                }
                self::fail($consumer . ' must refuse a bundle with a pending journal.');
            } catch (\RuntimeException $exception) {
                self::assertStringContainsString('agent-edit recover', $exception->getMessage(), $consumer);
            }
        }
    }

    public function testMapPlanKilledWhileAFileIsMovedAsideIsRestored(): void
    {
        $this->writeRenamePlan();
        $greeter = $this->project->read('src/Greeter.php');
        $runner = $this->project->read('src/Runner.php');
        // Each file: source -> backup, staged -> source. After rename 3 the first file is published and the second one
        // exists only as the publisher's backup: its path is missing.
        $this->runAndKill('map', 3);
        $greeterPresent = is_file($this->project->path('src/Greeter.php'));
        $runnerPresent = is_file($this->project->path('src/Runner.php'));
        self::assertTrue($greeterPresent xor $runnerPresent, 'exactly one source is displaced at the kill point');
        $displaced = $greeterPresent ? 'src/Runner.php' : 'src/Greeter.php';

        $result = (new EditEngine())->recover($this->project->root, '.agent-edit/receipts/crash');

        self::assertSame('rolled_back', $result['status']);
        self::assertContains(['path' => $displaced, 'state' => PublicationJournal::STATE_DISPLACED], $result['files']);
        self::assertSame($greeter, $this->project->read('src/Greeter.php'));
        self::assertSame($runner, $this->project->read('src/Runner.php'));
        self::assertSame([], $this->temporaryFiles());
    }

    public function testMapPlanKilledBeforeTheReceiptVerifiesAgainstARefreshedMap(): void
    {
        $this->writeRenamePlan();
        $this->runAndKill('map', 4);

        self::assertSame('published', (new EditEngine())->recover($this->project->root, '.agent-edit/receipts/crash')['status']);
        $receipt = $this->receipt();
        self::assertSame('git_status_diff', $receipt['changed_files_source']);
        self::assertSame(['src/Greeter.php', 'src/Runner.php'], $receipt['changed_files']);
        self::assertStringContainsString('function hello', $this->project->read('src/Greeter.php'));

        $this->rebuildMap();
        self::assertSame('passed', (new EditEngine())->verify($this->project->root, '.agent-edit/receipts/crash')['status']);
    }

    private function writeDirectPlan(): void
    {
        file_put_contents($this->project->path('plan.json'), json_encode([
            'type' => 'source_edit_plan',
            'contract_version' => '1.0',
            'target_id' => 'crash',
            'edits' => [
                ['path' => 'src/A.php', 'find' => 'return 1;', 'replace' => 'return 10;'],
                ['path' => 'src/B.php', 'find' => 'return 2;', 'replace' => 'return 20;'],
            ],
            'creates' => [
                ['path' => 'src/C.php', 'content' => "<?php\n\nnamespace App;\n\nfinal class C\n{\n}\n"],
            ],
        ], JSON_THROW_ON_ERROR));
    }

    private function writeRenamePlan(): void
    {
        $plan = (new MethodRenamePlanner())->plan($this->rebuildMap(), 'App\Greeter::greet', 'hello')->toArray();
        self::assertSame('safe', $plan['status'] ?? null);
        self::assertCount(2, array_unique(array_column($plan['edits'], 'path')), 'the rename touches two files');
        file_put_contents($this->project->path('plan.json'), json_encode($plan, JSON_THROW_ON_ERROR));
    }

    private function rebuildMap(): \voku\AgentMap\Index\AgentMapIndex
    {
        $map = CachedAgentMapBuilder::build($this->project->root, ['src']);
        if (!is_dir($this->project->path('.agent-map'))) {
            mkdir($this->project->path('.agent-map'));
        }
        (new IndexWriter())->write($map, $this->project->path('.agent-map/php-symbols.json'));

        return $map;
    }

    private function runAndKill(string $mode, int $holdAfterRenames): void
    {
        $marker = $this->aux . '/held';
        $process = $this->start($mode, $holdAfterRenames, $marker);
        $deadline = microtime(true) + 60;
        while (!is_file($marker)) {
            $status = proc_get_status($process);
            self::assertTrue($status['running'], 'worker exited before reaching the kill point');
            self::assertLessThan($deadline, microtime(true), 'timed out waiting for the kill point');
            usleep(10_000);
        }
        proc_terminate($process, 9); // SIGKILL: no shutdown function, no finally block, no rollback runs
        $deadline = microtime(true) + 10;
        while (proc_get_status($process)['running']) {
            self::assertLessThan($deadline, microtime(true), 'killed worker did not exit');
            usleep(10_000);
        }
        unlink($marker);
    }

    /** @return array{exit: int, stderr: string} */
    private function runChild(string $mode, int $holdAfterRenames): array
    {
        $process = $this->start($mode, $holdAfterRenames, $this->aux . '/held');
        $deadline = microtime(true) + 60;
        while (($status = proc_get_status($process))['running']) {
            self::assertLessThan($deadline, microtime(true), 'worker did not finish');
            usleep(10_000);
        }

        return ['exit' => $status['exitcode'], 'stderr' => (string) @file_get_contents($this->aux . '/stderr.txt')];
    }

    /** @return resource */
    private function start(string $mode, int $holdAfterRenames, string $marker)
    {
        $command = [
            PHP_BINARY, __DIR__ . '/Support/publication-kill-worker.php',
            $this->project->root, $this->project->path('plan.json'), $this->bundle(),
            $mode, (string) $holdAfterRenames, $marker,
        ];
        if ($mode === 'map') {
            $command[] = $this->project->path('.agent-map/php-symbols.json');
        }
        $process = proc_open($command, [1 => ['file', '/dev/null', 'w'], 2 => ['file', $this->aux . '/stderr.txt', 'w']], $pipes);
        self::assertIsResource($process);
        $this->processes[] = $process;

        return $process;
    }

    private function bundle(): string
    {
        return $this->project->path('.agent-edit/receipts/crash');
    }

    /** @return array<string, mixed> */
    private function receipt(): array
    {
        return json_decode((string) file_get_contents($this->bundle() . '/' . EditEngine::RECEIPT_FILE), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return list<string> */
    private function temporaryFiles(): array
    {
        return array_values(array_filter($this->project->files(), static fn (string $path): bool => str_contains($path, '.agent-edit-tx-') || str_contains($path, '.agent-edit-plan-')));
    }

    /** @return array<string, string> every project file (bundle included, `.git` excluded) with its digest */
    private function snapshot(): array
    {
        $files = [];
        foreach ($this->project->files() as $path) {
            // The bundle is included: a conflict must not rewrite the journal or write a receipt either.
            if (!str_starts_with($path, '.git/')) {
                $files[$path] = $this->project->hash($path);
            }
        }

        return $files;
    }
}
