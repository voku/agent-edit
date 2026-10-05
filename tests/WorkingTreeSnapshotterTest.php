<?php

declare(strict_types=1);

namespace voku\AgentEdit\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentEdit\Apply\WorkingTreeSnapshot;
use voku\AgentEdit\Apply\WorkingTreeSnapshotter;

/** Observation contract of the snapshotter: which paths count as changed, and when the answer is "unknown". */
final class WorkingTreeSnapshotterTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = (string) realpath(sys_get_temp_dir()) . '/agent-edit-snapshot-' . bin2hex(random_bytes(4));
        mkdir($this->base, 0o775, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->base));
    }

    public function testPathsAreRootRelativeWhenTheProjectIsASubdirectoryOfTheGitWorkTree(): void
    {
        $top = $this->base . '/top';
        mkdir($top . '/app/src', 0o775, true);
        file_put_contents($top . '/app/src/A.php', "<?php\n");
        file_put_contents($top . '/outside.txt', "x\n");
        $this->commitAll($top);

        $snapshotter = new WorkingTreeSnapshotter();
        $before = $snapshotter->capture($top . '/app');
        file_put_contents($top . '/app/src/A.php', "<?php // changed\n");
        file_put_contents($top . '/outside.txt', "y\n");
        $after = $snapshotter->capture($top . '/app');

        // Reported relative to the project root; changes outside it are not attributed to the project.
        self::assertSame(['src/A.php'], $after->changedPathsSince($before));
    }

    public function testNoRepositoryYieldsAnUnavailableSnapshotInsteadOfThrowing(): void
    {
        $snapshot = (new WorkingTreeSnapshotter())->capture($this->base);

        self::assertFalse($snapshot->available);
        self::assertSame([], $snapshot->entries);
        self::assertSame([], $snapshot->changedPathsSince($snapshot), 'unavailable evidence reports no paths and is told apart by `available`');
    }

    public function testProjectDirectoryWhitespaceDoesNotHideChanges(): void
    {
        $top = $this->base . '/top';
        $project = $top . '/ app';
        mkdir($project . '/src', 0o775, true);
        file_put_contents($project . '/src/A.php', "<?php\n");
        $this->commitAll($top);

        $snapshotter = new WorkingTreeSnapshotter();
        $before = $snapshotter->capture($project);
        file_put_contents($project . '/src/A.php', "<?php // changed\n");

        self::assertSame(['src/A.php'], $snapshotter->capture($project)->changedPathsSince($before));
    }

    public function testGitRootWhitespaceDoesNotHideChangesToAnAlreadyDirtyFile(): void
    {
        $repository = $this->base . '/repo ';
        mkdir($repository . '/src', 0o775, true);
        file_put_contents($repository . '/src/A.php', "<?php\n");
        $this->commitAll($repository);
        file_put_contents($repository . '/src/A.php', "<?php // first change\n");

        $snapshotter = new WorkingTreeSnapshotter();
        $before = $snapshotter->capture($repository);
        file_put_contents($repository . '/src/A.php', "<?php // second change\n");

        self::assertSame(['src/A.php'], $snapshotter->capture($repository)->changedPathsSince($before));
    }

    public function testRealGitChangesIncludeUntrackedAndDeletedFiles(): void
    {
        $repository = $this->base . '/repo';
        mkdir($repository . '/src', 0o775, true);
        file_put_contents($repository . '/src/Kept.php', "<?php\n");
        file_put_contents($repository . '/src/Removed.php', "<?php\n");
        $this->commitAll($repository);

        $snapshotter = new WorkingTreeSnapshotter();
        $before = $snapshotter->capture($repository);
        self::assertTrue($before->available);
        self::assertSame([], $before->entries, 'a clean tree has no dirty entries to hash');

        file_put_contents($repository . '/src/Kept.php', "<?php\n// edited\n");
        file_put_contents($repository . '/src/Added.php', "<?php\n");
        unlink($repository . '/src/Removed.php');

        self::assertSame(
            ['src/Added.php', 'src/Kept.php', 'src/Removed.php'],
            $snapshotter->capture($repository)->changedPathsSince($before),
        );
    }

    public function testChangesInsideALinkedGitWorktreeAreObserved(): void
    {
        $repository = $this->base . '/worktree-source';
        mkdir($repository . '/src', 0o775, true);
        file_put_contents($repository . '/src/Probe.php', "<?php\n");
        $this->commitAll($repository);

        $worktree = $this->base . '/linked-worktree';
        $this->git($repository, ['git', 'worktree', 'add', '-q', '--detach', $worktree, 'HEAD']);
        self::assertFileExists($worktree . '/.git');
        self::assertFalse(is_dir($worktree . '/.git'), 'linked worktrees use a .git file, not a directory');

        $snapshotter = new WorkingTreeSnapshotter();
        $before = $snapshotter->capture($worktree);
        self::assertTrue($before->available);
        self::assertSame([], $before->entries);

        file_put_contents($worktree . '/src/Probe.php', "<?php\n// edited in linked worktree\n");

        self::assertSame(['src/Probe.php'], $snapshotter->capture($worktree)->changedPathsSince($before));
    }

    public function testAFileRestoredToItsOriginalContentIsNotReportedAsChanged(): void
    {
        $before = new WorkingTreeSnapshot(true, 'abc', ['src/A.php' => ' M:same']);
        $after = new WorkingTreeSnapshot(true, 'abc', ['src/A.php' => ' M:same']);

        self::assertSame([], $after->changedPathsSince($before));
    }

    private function commitAll(string $repository): void
    {
        foreach ([
            ['git', 'init', '-q'],
            ['git', 'config', 'user.email', 'test@example.invalid'],
            ['git', 'config', 'user.name', 'test'],
            ['git', 'add', '-A'],
            ['git', '-c', 'commit.gpgsign=false', 'commit', '-q', '-m', 'baseline'],
        ] as $command) {
            $this->git($repository, $command);
        }
    }

    /** @param non-empty-list<string> $command */
    private function git(string $workingDirectory, array $command): void
    {
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $workingDirectory);
        self::assertIsResource($process);
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), 'git ' . implode(' ', $command) . ' failed');
    }
}
