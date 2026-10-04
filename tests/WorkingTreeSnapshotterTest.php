<?php

declare(strict_types=1);

namespace voku\AgentEdit\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentEdit\Apply\WorkingTreeSnapshotter;

final class WorkingTreeSnapshotterTest extends TestCase
{
    public function testPathsAreRootRelativeWhenTheProjectIsASubdirectoryOfTheGitWorkTree(): void
    {
        $top = (string) realpath(sys_get_temp_dir()) . '/agent-edit-snapshot-' . bin2hex(random_bytes(4));
        mkdir($top . '/app/src', 0o775, true);
        file_put_contents($top . '/app/src/A.php', "<?php\n");
        file_put_contents($top . '/outside.txt', "x\n");
        exec('cd ' . escapeshellarg($top) . ' && git init -q . && git add -A && git -c user.email=t@example.invalid -c user.name=t commit -qm init', $output, $exit);
        self::assertSame(0, $exit, implode("\n", $output));

        $snapshotter = new WorkingTreeSnapshotter();
        $before = $snapshotter->capture($top . '/app');
        file_put_contents($top . '/app/src/A.php', "<?php // changed\n");
        file_put_contents($top . '/outside.txt', "y\n");
        $after = $snapshotter->capture($top . '/app');

        // Reported relative to the project root; changes outside it are not attributed to the project.
        self::assertSame(['src/A.php'], $after->changedPathsSince($before));

        exec('rm -rf ' . escapeshellarg($top));
    }
}
