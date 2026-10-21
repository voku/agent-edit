<?php

declare(strict_types=1);

namespace voku\AgentEdit\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentEdit\Apply\FileOperation;
use voku\AgentEdit\Apply\FileTransaction;

final class FileTransactionTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-edit-tx-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0o775, true);
        file_put_contents($this->root . '/src/A.php', "<?php\n\nfinal class A {}\n");
        file_put_contents($this->root . '/src/B.php', "<?php\n\nfinal class B {}\n");
        file_put_contents($this->root . '/notes.txt', "plain\n");
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

    public function testWritesCreatesAndDeletesInOneTransaction(): void
    {
        $warnings = (new FileTransaction())->run([
            FileOperation::write($this->root . '/src/A.php', "<?php\n\nfinal class A2 {}\n", $this->hash('src/A.php')),
            FileOperation::write($this->root . '/tests/new/ANewTest.php', "<?php\n\nfinal class ANewTest {}\n", null),
            FileOperation::delete($this->root . '/notes.txt', $this->hash('notes.txt')),
        ], $this->root);

        self::assertSame([], $warnings);
        self::assertStringContainsString('A2', (string) file_get_contents($this->root . '/src/A.php'));
        self::assertFileExists($this->root . '/tests/new/ANewTest.php');
        self::assertFileDoesNotExist($this->root . '/notes.txt');
        self::assertSame([], $this->leftovers());
    }

    public function testPreservesPermissionsOfRewrittenFiles(): void
    {
        chmod($this->root . '/src/A.php', 0o600);

        (new FileTransaction())->run([
            FileOperation::write($this->root . '/src/A.php', "<?php\n\nfinal class A3 {}\n", $this->hash('src/A.php')),
        ], $this->root);

        self::assertSame(0o600, fileperms($this->root . '/src/A.php') & 0o777);
    }

    public function testStaleHashRefusesBeforeAnyWrite(): void
    {
        $stale = $this->hash('src/A.php');
        file_put_contents($this->root . '/src/A.php', "<?php\n\nfinal class Changed {}\n");

        try {
            (new FileTransaction())->run([
                FileOperation::write($this->root . '/src/B.php', "<?php\n\nfinal class B2 {}\n", $this->hash('src/B.php')),
                FileOperation::write($this->root . '/src/A.php', "<?php\n\nfinal class A2 {}\n", $stale),
            ], $this->root);
            self::fail('A stale precondition must refuse the whole transaction.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('src/A.php', $exception->getMessage());
        }

        self::assertStringContainsString('final class B {}', (string) file_get_contents($this->root . '/src/B.php'));
        self::assertSame([], $this->leftovers());
    }

    public function testCreateRefusesExistingTarget(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already exists');

        (new FileTransaction())->run([
            FileOperation::write($this->root . '/src/A.php', "<?php\n", null),
        ], $this->root);
    }

    public function testSyntaxErrorInAnyStagedPhpFileLeavesEverythingUntouchedAndRemovesCreatedDirectories(): void
    {
        try {
            (new FileTransaction())->run([
                FileOperation::write($this->root . '/src/A.php', "<?php\n\nfinal class A2 {}\n", $this->hash('src/A.php')),
                FileOperation::write($this->root . '/deep/er/Broken.php', "<?php\n\nfinal class {\n", null),
            ], $this->root);
            self::fail('A syntax error must refuse the transaction.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('syntax validation', $exception->getMessage());
            self::assertStringContainsString('deep/er/Broken.php', $exception->getMessage());
            self::assertStringNotContainsString('agent-edit-tx-stage', $exception->getMessage());
        }

        self::assertStringContainsString('final class A {}', (string) file_get_contents($this->root . '/src/A.php'));
        self::assertDirectoryDoesNotExist($this->root . '/deep');
        self::assertSame([], $this->leftovers());
    }

    public function testNonPhpFilesAreNotSyntaxChecked(): void
    {
        (new FileTransaction())->run([
            FileOperation::write($this->root . '/notes.txt', "<?php this is not php {{\n", $this->hash('notes.txt')),
        ], $this->root);

        self::assertStringContainsString('not php', (string) file_get_contents($this->root . '/notes.txt'));
    }

    public function testFailureWhilePublishingRestoresEveryFileAndRemovesCreatedOnes(): void
    {
        $renames = 0;
        $transaction = new FileTransaction(
            static function (string $from, string $to) use (&$renames): bool {
                ++$renames;

                return $renames === 5 ? false : rename($from, $to);
            },
        );

        try {
            $transaction->run([
                FileOperation::write($this->root . '/new/Created.php', "<?php\n\nfinal class Created {}\n", null),
                FileOperation::write($this->root . '/src/A.php', "<?php\n\nfinal class A2 {}\n", $this->hash('src/A.php')),
                FileOperation::write($this->root . '/src/B.php', "<?php\n\nfinal class B2 {}\n", $this->hash('src/B.php')),
            ], $this->root);
            self::fail('The injected publication failure must surface.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('every file was restored', $exception->getMessage());
        }

        self::assertStringContainsString('final class A {}', (string) file_get_contents($this->root . '/src/A.php'));
        self::assertStringContainsString('final class B {}', (string) file_get_contents($this->root . '/src/B.php'));
        self::assertFileDoesNotExist($this->root . '/new/Created.php');
        self::assertDirectoryDoesNotExist($this->root . '/new');
        self::assertSame([], $this->leftovers());
    }

    public function testFailedDeleteIsRolledBack(): void
    {
        $renames = 0;
        $transaction = new FileTransaction(
            static function (string $from, string $to) use (&$renames): bool {
                ++$renames;

                return $renames === 3 ? false : rename($from, $to);
            },
        );

        try {
            $transaction->run([
                FileOperation::delete($this->root . '/notes.txt', $this->hash('notes.txt')),
                FileOperation::write($this->root . '/src/A.php', "<?php\n\nfinal class A2 {}\n", $this->hash('src/A.php')),
            ], $this->root);
            self::fail('The injected publication failure must surface.');
        } catch (RuntimeException) {
        }

        self::assertSame("plain\n", file_get_contents($this->root . '/notes.txt'));
        self::assertStringContainsString('final class A {}', (string) file_get_contents($this->root . '/src/A.php'));
        self::assertSame([], $this->leftovers());
    }

    public function testRejectsPathsOutsideTheRoot(): void
    {
        $outside = sys_get_temp_dir() . '/agent-edit-outside-' . bin2hex(random_bytes(4)) . '.php';

        try {
            (new FileTransaction())->run([FileOperation::write($outside, "<?php\n", null)], $this->root);
            self::fail('A path outside the root must be refused.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('escapes the project root', $exception->getMessage());
        }
        self::assertFileDoesNotExist($outside);
    }

    public function testRefusesSymlinkedTargets(): void
    {
        symlink($this->root . '/notes.txt', $this->root . '/link.txt');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not a regular file');

        (new FileTransaction())->run([
            FileOperation::write($this->root . '/link.txt', "x\n", $this->hash('notes.txt')),
        ], $this->root);
    }

    public function testRefusesCreationBehindASymlinkedDirectoryThatLeavesTheRoot(): void
    {
        $outside = sys_get_temp_dir() . '/agent-edit-outside-dir-' . bin2hex(random_bytes(4));
        mkdir($outside);
        symlink($outside, $this->root . '/escape');

        try {
            (new FileTransaction())->run([
                FileOperation::write($this->root . '/escape/Evil.php', "<?php\n", null),
            ], $this->root);
            self::fail('A symlinked directory leaving the root must be refused.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('escapes the project root', $exception->getMessage());
        } finally {
            self::assertSame([], array_values(array_diff((array) scandir($outside), ['.', '..'])));
            rmdir($outside);
        }
    }

    public function testRejectsDuplicatePathsAndEmptyTransactions(): void
    {
        try {
            (new FileTransaction())->run([
                FileOperation::write($this->root . '/src/A.php', "<?php\n", $this->hash('src/A.php')),
                FileOperation::write($this->root . '/src/A.php', "<?php\n", $this->hash('src/A.php')),
            ], $this->root);
            self::fail('Duplicate paths must be refused.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('same path twice', $exception->getMessage());
        }

        $this->expectException(RuntimeException::class);
        (new FileTransaction())->run([], $this->root);
    }

    private function hash(string $relative): string
    {
        return (string) hash_file('sha256', $this->root . '/' . $relative);
    }

    /** @return list<string> */
    private function leftovers(): array
    {
        $found = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($iterator as $item) {
            if (str_contains($item->getFilename(), 'agent-edit-tx-')) {
                $found[] = $item->getPathname();
            }
        }

        return $found;
    }
}
