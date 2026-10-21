<?php

declare(strict_types=1);

namespace voku\AgentEdit\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentEdit\Apply\WorkingTreeSnapshotter;
use voku\AgentEdit\EditEngine;
use voku\AgentEdit\Receipt\ApplyRequest;

/** Direct edits of files Git cannot observe (ignored) or that were already dirty must verify honestly. */
final class DirectEditObservationTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-edit-observe-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0o775, true);
        file_put_contents($this->root . '/.gitignore', "local.php\ngenerated/\n.agent-edit/\n");
        file_put_contents($this->root . '/local.php', "<?php\n\nreturn ['debug' => false];\n");
        file_put_contents($this->root . '/src/Tracked.php', "<?php\n\nreturn 1;\n");
        file_put_contents($this->root . '/notes.txt', "one\n");
        $this->git('init -q');
        $this->git('config user.email test@example.invalid');
        $this->git('config user.name test');
        $this->git('add -A && git commit -qm init');
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

    public function testSnapshotterReportsOnlyIgnoredPathsAndNeverTrackedOnes(): void
    {
        mkdir($this->root . '/generated');
        file_put_contents($this->root . '/generated/Out.php', "<?php\n");

        $ignored = (new WorkingTreeSnapshotter())->ignoredPaths($this->root, ['local.php', 'src/Tracked.php', 'generated/Out.php', 'notes.txt', 'brand/new.php']);

        self::assertSame(['generated/Out.php', 'local.php'], $ignored);
    }

    public function testSnapshotterIgnoredPathsAreEmptyOutsideARepositoryAndForNoInput(): void
    {
        $plain = sys_get_temp_dir() . '/agent-edit-not-a-repo-' . bin2hex(random_bytes(4));
        mkdir($plain);
        try {
            self::assertSame([], (new WorkingTreeSnapshotter())->ignoredPaths($plain, ['a.php']));
        } finally {
            rmdir($plain);
        }
        self::assertSame([], (new WorkingTreeSnapshotter())->ignoredPaths($this->root, []));
        self::assertSame([], (new WorkingTreeSnapshotter())->ignoredPaths($this->root . '/missing', ['a.php']));
    }

    public function testEditingAnIgnoredFileVerifiesByDigestAndSaysSo(): void
    {
        $this->apply([['path' => 'local.php', 'find' => "'debug' => false", 'replace' => "'debug' => true"]], 'ig');

        $execution = $this->json('.agent-edit/receipts/ig/execution.json');
        self::assertSame([], $execution['changed_files'], 'git status cannot see an ignored file');
        self::assertSame(['local.php'], $execution['direct_edit']['git_ignored']);

        $result = (new EditEngine())->verify($this->root, '.agent-edit/receipts/ig');
        self::assertSame('passed', $result['status']);
        self::assertSame(['local.php'], $result['git_ignored_files_proven_by_digest_only']);
        self::assertSame(['local.php'], $result['changed_files']);
    }

    public function testMixedIgnoredAndTrackedEditsStillRequireTheTrackedFileToBeObserved(): void
    {
        $this->apply([
            ['path' => 'local.php', 'find' => "'debug' => false", 'replace' => "'debug' => true"],
            ['path' => 'src/Tracked.php', 'find' => 'return 1;', 'replace' => 'return 2;'],
        ], 'mixed');

        $execution = $this->json('.agent-edit/receipts/mixed/execution.json');
        self::assertSame(['src/Tracked.php'], $execution['changed_files']);

        $result = (new EditEngine())->verify($this->root, '.agent-edit/receipts/mixed');
        self::assertSame('passed', $result['status']);
        self::assertSame(['local.php', 'src/Tracked.php'], $result['changed_files']);

        // Removing the tracked file from the observation must still fail: only ignored paths are exempt.
        $path = $this->root . '/.agent-edit/receipts/mixed/execution.json';
        $execution['changed_files'] = [];
        file_put_contents($path, json_encode($execution, JSON_THROW_ON_ERROR));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('do not exactly match plan scope (expected src/Tracked.php; got )');
        (new EditEngine())->verify($this->root, '.agent-edit/receipts/mixed');
    }

    public function testForgedIgnoredListCannotHideAnUnobservedTrackedChange(): void
    {
        $this->apply([['path' => 'src/Tracked.php', 'find' => 'return 1;', 'replace' => 'return 2;']], 'forged');
        $path = $this->root . '/.agent-edit/receipts/forged/execution.json';
        $execution = $this->json('.agent-edit/receipts/forged/execution.json');
        $execution['direct_edit']['git_ignored'] = ['src/Tracked.php'];
        $execution['changed_files'] = [];
        file_put_contents($path, json_encode($execution, JSON_THROW_ON_ERROR));

        // The list is accepted only as a subset of the plan; but a tracked file claimed "ignored" is now digest-only,
        // so the digest check is what must still hold: change the file and it must fail.
        file_put_contents($this->root . '/src/Tracked.php', "<?php\n\nreturn 3;\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('changed after the edit was applied');
        (new EditEngine())->verify($this->root, '.agent-edit/receipts/forged');
    }

    public function testIgnoredListWithPathsOutsideThePlanIsRejected(): void
    {
        $this->apply([['path' => 'local.php', 'find' => "'debug' => false", 'replace' => "'debug' => true"]], 'bad');
        $path = $this->root . '/.agent-edit/receipts/bad/execution.json';
        $execution = $this->json('.agent-edit/receipts/bad/execution.json');
        $execution['direct_edit']['git_ignored'] = ['somewhere/else.php'];
        file_put_contents($path, json_encode($execution, JSON_THROW_ON_ERROR));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('invalid git_ignored evidence');
        (new EditEngine())->verify($this->root, '.agent-edit/receipts/bad');
    }

    public function testAnAlreadyDirtyFileIsAttributedOnlyForTheBytesTheEditChanged(): void
    {
        file_put_contents($this->root . '/notes.txt', "one\nuncommitted work\n");

        $this->apply([['path' => 'notes.txt', 'find' => 'one', 'replace' => 'uno']], 'dirty');

        self::assertSame("uno\nuncommitted work\n", $this->read('notes.txt'));
        $result = (new EditEngine())->verify($this->root, '.agent-edit/receipts/dirty');
        self::assertSame('passed', $result['status']);

        (new EditEngine())->undo($this->root, '.agent-edit/receipts/dirty');
        self::assertSame("one\nuncommitted work\n", $this->read('notes.txt'), 'Undo restores the pre-edit state, including earlier uncommitted work.');
    }

    public function testAnEditThatRestoresACleanFileIsStillObservedAsChanged(): void
    {
        file_put_contents($this->root . '/notes.txt', "one dirty\n");

        $this->apply([['path' => 'notes.txt', 'find' => 'one dirty', 'replace' => 'one']], 'clean');

        $execution = $this->json('.agent-edit/receipts/clean/execution.json');
        self::assertSame(['notes.txt'], $execution['changed_files']);
        self::assertSame('passed', (new EditEngine())->verify($this->root, '.agent-edit/receipts/clean')['status']);
    }

    public function testFileNamesWithSpacesAndUnicodeAreObservedAndUndone(): void
    {
        mkdir($this->root . '/docs dir');
        file_put_contents($this->root . '/docs dir/größe notes.txt', "alpha\n");
        $this->git('add -A && git commit -qm names');

        $this->apply([['path' => 'docs dir/größe notes.txt', 'find' => 'alpha', 'replace' => 'beta']], 'names');

        $execution = $this->json('.agent-edit/receipts/names/execution.json');
        self::assertSame(['docs dir/größe notes.txt'], $execution['changed_files']);
        self::assertSame('passed', (new EditEngine())->verify($this->root, '.agent-edit/receipts/names')['status']);
        (new EditEngine())->undo($this->root, '.agent-edit/receipts/names');
        self::assertSame("alpha\n", $this->read('docs dir/größe notes.txt'));
    }

    public function testReusingAReceiptLabelOnTheCommandLineDoesNotOverwriteEarlierEvidence(): void
    {
        self::assertSame(0, $this->edit('one', 'two')[0]);
        $first = $this->read('.agent-edit/receipts/same/execution.json');

        [$exit, , $stderr] = $this->edit('two', 'three');

        self::assertSame(1, $exit);
        self::assertStringContainsString('receipt bundle already exists', $stderr);
        self::assertSame($first, $this->read('.agent-edit/receipts/same/execution.json'));
        self::assertSame("two\n", $this->read('notes.txt'));
    }

    /** @return array{int, string, string} */
    private function edit(string $find, string $replace): array
    {
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__) . '/bin/agent-edit', 'edit', 'notes.txt', '--find=' . $find, '--replace=' . $replace, '--task', 'same'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->root,
        );
        self::assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }

    /** @param list<array<string, string>> $edits */
    private function apply(array $edits, string $label): void
    {
        $dir = $this->root . '/.agent-edit/receipts/' . $label;
        mkdir($dir, 0o775, true);
        file_put_contents($dir . '/plan.json', json_encode([
            'type' => 'source_edit_plan',
            'contract_version' => '1.0',
            'target_id' => $label,
            'edits' => $edits,
        ], JSON_THROW_ON_ERROR));

        (new EditEngine())->applyWithReceipt(new ApplyRequest($this->root, $dir . '/plan.json', null, $this->root, $dir, $label));
    }

    private function git(string $arguments): void
    {
        shell_exec('cd ' . escapeshellarg($this->root) . ' && git ' . $arguments . ' 2>&1');
    }

    private function read(string $relative): string
    {
        return (string) file_get_contents($this->root . '/' . $relative);
    }

    /** @return array<string, mixed> */
    private function json(string $relative): array
    {
        $decoded = json_decode($this->read($relative), true, 512, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }
}
