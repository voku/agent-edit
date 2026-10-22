<?php

declare(strict_types=1);

namespace voku\AgentEdit\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentEdit\Apply\SourceEditPlanApplier;

final class SourceEditPlanApplierTest extends TestCase
{
    private const SERVICE = <<<'PHP'
<?php

declare(strict_types=1);

namespace Demo;

final class Service
{
    public function total(int $a, int $b): int
    {
        return $a - $b;
    }

    public function double(int $a): int
    {
        return $a * 2;
    }

    private function helper(int $a): int
    {
        return $a;
    }
}
PHP;

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-edit-direct-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0o775, true);
        file_put_contents($this->root . '/src/Service.php', self::SERVICE);
        file_put_contents($this->root . '/src/Other.php', "<?php\n\nnamespace Demo;\n\nfinal class Other\n{\n}\n");
        file_put_contents($this->root . '/README.md', "# Demo\n\nUse `old()` here.\nUse `old()` there.\n");
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

    /**
     * @param list<array<string, mixed>> $edits
     * @param list<array<string, mixed>> $creates
     * @return array<string, mixed>
     */
    private function plan(array $edits, array $creates = [], bool $allowSignatureChange = false): array
    {
        return array_filter([
            'type' => 'source_edit_plan',
            'contract_version' => '1.0',
            'target_id' => 'test',
            'allow_signature_change' => $allowSignatureChange ?: null,
            'edits' => $edits,
            'creates' => $creates ?: null,
        ], static fn (mixed $value): bool => $value !== null);
    }

    public function testReplacesUniqueAnchorAndLeavesNoTemporaryFiles(): void
    {
        $result = (new SourceEditPlanApplier())->apply($this->plan([
            ['path' => 'src/Service.php', 'find' => 'return $a - $b;', 'replace' => 'return $a + $b;'],
        ]), $this->root);

        self::assertTrue($result->succeeded());
        self::assertStringContainsString('return $a + $b;', $this->read('src/Service.php'));
        self::assertStringContainsString('return $a * 2;', $this->read('src/Service.php'));
        self::assertSame([], $this->temporaryArtifacts());
        self::assertSame('src/Service.php', $result->evidence['files'][0]['path']);
        self::assertSame(hash('sha256', self::SERVICE), $result->evidence['files'][0]['before_sha256']);
        self::assertSame(hash('sha256', $this->read('src/Service.php')), $result->evidence['files'][0]['after_sha256']);
        self::assertSame(self::SERVICE, $result->evidence['pre_images']['src/Service.php']);
    }

    public function testSeveralEditsInOneFileAreResolvedAgainstTheOriginalRegardlessOfOrder(): void
    {
        (new SourceEditPlanApplier())->apply($this->plan([
            ['path' => 'src/Service.php', 'find' => 'return $a * 2;', 'replace' => "\$twice = \$a * 2;\n\n        return \$twice;"],
            ['path' => 'src/Service.php', 'find' => 'return $a - $b;', 'replace' => 'return $a + $b + $a + $b;'],
        ]), $this->root);

        $source = $this->read('src/Service.php');
        self::assertStringContainsString('return $a + $b + $a + $b;', $source);
        self::assertStringContainsString('$twice = $a * 2;', $source);
    }

    public function testEditsAcrossFilesAreAllOrNothing(): void
    {
        $originalOther = $this->read('src/Other.php');

        try {
            (new SourceEditPlanApplier())->apply($this->plan([
                ['path' => 'src/Service.php', 'find' => 'return $a - $b;', 'replace' => 'return $a + $b;'],
                ['path' => 'src/Other.php', 'find' => 'not there at all', 'replace' => 'x'],
            ]), $this->root);
            self::fail('A single unprovable edit must refuse the whole plan.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('edits[1]', $exception->getMessage());
        }

        self::assertSame(self::SERVICE, $this->read('src/Service.php'));
        self::assertSame($originalOther, $this->read('src/Other.php'));
        self::assertSame([], $this->temporaryArtifacts());
    }

    public function testAmbiguousAnchorListsLinesAndExplainsTheThreeWaysOut(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('occurs 3 times in src/Service.php (lines 11, 16, 21)');

        (new SourceEditPlanApplier())->apply($this->plan([
            ['path' => 'src/Service.php', 'find' => 'return $a', 'replace' => 'return 1 + $a'],
        ]), $this->root);
    }

    public function testAmbiguityMessageNamesOccurrenceAndAll(): void
    {
        try {
            (new SourceEditPlanApplier())->preflight($this->plan([
                ['path' => 'README.md', 'find' => '`old()`', 'replace' => '`new()`'],
            ]), $this->root);
            self::fail('Expected an ambiguity refusal.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('"occurrence"', $exception->getMessage());
            self::assertStringContainsString('"all": true', $exception->getMessage());
        }
    }

    public function testOccurrencePicksExactlyOneMatch(): void
    {
        (new SourceEditPlanApplier())->apply($this->plan([
            ['path' => 'README.md', 'find' => '`old()`', 'replace' => '`new()`', 'occurrence' => 2],
        ]), $this->root);

        self::assertSame("# Demo\n\nUse `old()` here.\nUse `new()` there.\n", $this->read('README.md'));
    }

    public function testOccurrenceBeyondTheMatchCountIsRefused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('occurrence 3 requested but `find` occurs only 2 time(s)');

        (new SourceEditPlanApplier())->preflight($this->plan([
            ['path' => 'README.md', 'find' => '`old()`', 'replace' => '`new()`', 'occurrence' => 3],
        ]), $this->root);
    }

    public function testAllReplacesEveryMatch(): void
    {
        $result = (new SourceEditPlanApplier())->apply($this->plan([
            ['path' => 'README.md', 'find' => '`old()`', 'replace' => '`new()`', 'all' => true],
        ]), $this->root);

        self::assertSame("# Demo\n\nUse `new()` here.\nUse `new()` there.\n", $this->read('README.md'));
        self::assertSame(2, $result->evidence['files'][0]['edits']);
    }

    public function testAllStillRequiresAtLeastOneMatch(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not occur');

        (new SourceEditPlanApplier())->preflight($this->plan([
            ['path' => 'README.md', 'find' => '`nothing()`', 'replace' => 'x', 'all' => true],
        ]), $this->root);
    }

    public function testMissingAnchorExplainsWhitespaceDifferencesAndPointsAtTheRealLine(): void
    {
        try {
            (new SourceEditPlanApplier())->preflight($this->plan([
                ['path' => 'src/Service.php', 'find' => "public function double(int \$a): int\n    {\n        return \$a  *  2;\n    }", 'replace' => 'x'],
            ]), $this->root);
            self::fail('Expected a refusal.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('different whitespace', $exception->getMessage());
            self::assertStringContainsString('line 14', $exception->getMessage());
            self::assertStringContainsString('Copy the exact bytes', $exception->getMessage());
        }
    }

    public function testMissingAnchorPointsAtTheClosestLineWhenTheTextDiffers(): void
    {
        try {
            (new SourceEditPlanApplier())->preflight($this->plan([
                ['path' => 'src/Service.php', 'find' => 'public function totals(int $a, int $b): int', 'replace' => 'x'],
            ]), $this->root);
            self::fail('Expected a refusal.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('closest line is 9', $exception->getMessage());
            self::assertStringContainsString('public function total(int $a, int $b): int', $exception->getMessage());
        }
    }

    public function testMissingAnchorWithNothingSimilarSaysToRereadTheFile(): void
    {
        try {
            (new SourceEditPlanApplier())->preflight($this->plan([
                ['path' => 'README.md', 'find' => 'zzzzzzzzzzzzzzzzzzzzzz', 'replace' => 'x'],
            ]), $this->root);
            self::fail('Expected a refusal.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('Re-read the file', $exception->getMessage());
        }
    }

    public function testCrLfFilesRequireExactBytesAndExplainTheMismatch(): void
    {
        file_put_contents($this->root . '/crlf.txt', "one\r\ntwo\r\nthree\r\n");

        try {
            (new SourceEditPlanApplier())->preflight($this->plan([
                ['path' => 'crlf.txt', 'find' => "one\ntwo", 'replace' => 'x'],
            ]), $this->root);
            self::fail('LF text must not silently match CRLF content.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('different whitespace', $exception->getMessage());
        }

        (new SourceEditPlanApplier())->apply($this->plan([
            ['path' => 'crlf.txt', 'find' => "one\r\ntwo", 'replace' => "uno\r\ndos"],
        ]), $this->root);
        self::assertSame("uno\r\ndos\r\nthree\r\n", $this->read('crlf.txt'));
    }

    public function testMultibyteTextIsReplacedByBytesWithoutCorruption(): void
    {
        file_put_contents($this->root . '/i18n.txt', "größe: 10\nstraße: ü\n");

        (new SourceEditPlanApplier())->apply($this->plan([
            ['path' => 'i18n.txt', 'find' => 'straße: ü', 'replace' => 'straße: ö 日本'],
        ]), $this->root);

        self::assertSame("größe: 10\nstraße: ö 日本\n", $this->read('i18n.txt'));
    }

    public function testStaleExpectedSourceDigestIsRefused(): void
    {
        $stale = 'sha256:' . hash('sha256', self::SERVICE);
        file_put_contents($this->root . '/src/Service.php', self::SERVICE . "\n// changed\n");

        try {
            (new SourceEditPlanApplier())->apply($this->plan([
                ['path' => 'src/Service.php', 'find' => 'return $a * 2;', 'replace' => 'return $a << 1;', 'expected_source_sha256' => $stale],
            ]), $this->root);
            self::fail('A stale digest must refuse the edit.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('changed since you read it', $exception->getMessage());
        }
        self::assertStringNotContainsString('<<', $this->read('src/Service.php'));
    }

    public function testMatchingExpectedSourceDigestIsAccepted(): void
    {
        (new SourceEditPlanApplier())->apply($this->plan([
            ['path' => 'src/Service.php', 'find' => 'return $a * 2;', 'replace' => 'return $a << 1;', 'expected_source_sha256' => 'sha256:' . hash('sha256', self::SERVICE)],
        ]), $this->root);

        self::assertStringContainsString('<< 1', $this->read('src/Service.php'));
    }

    public function testOverlappingEditsAreRefusedWithTheLine(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('overlap in src/Service.php (around line 16)');

        (new SourceEditPlanApplier())->preflight($this->plan([
            ['path' => 'src/Service.php', 'find' => 'return $a * 2;', 'replace' => 'return 1;'],
            ['path' => 'src/Service.php', 'find' => '$a * 2', 'replace' => '2'],
        ]), $this->root);
    }

    public function testDeletionWithEmptyReplacement(): void
    {
        (new SourceEditPlanApplier())->apply($this->plan([
            ['path' => 'README.md', 'find' => "Use `old()` there.\n", 'replace' => ''],
        ]), $this->root);

        self::assertSame("# Demo\n\nUse `old()` here.\n", $this->read('README.md'));
    }

    public function testMissingFileExplainsHowToCreateOne(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('use "creates"');

        (new SourceEditPlanApplier())->preflight($this->plan([
            ['path' => 'src/Nope.php', 'find' => 'a', 'replace' => 'b'],
        ]), $this->root);
    }

    public function testBinaryFilesAreNotEdited(): void
    {
        file_put_contents($this->root . '/blob.bin', "abc\0def");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('looks binary');

        (new SourceEditPlanApplier())->preflight($this->plan([
            ['path' => 'blob.bin', 'find' => 'abc', 'replace' => 'x'],
        ]), $this->root);
    }

    public function testDirectoriesAndSymlinksAreNotEditable(): void
    {
        symlink($this->root . '/README.md', $this->root . '/link.md');

        foreach (['src', 'link.md'] as $path) {
            try {
                (new SourceEditPlanApplier())->preflight($this->plan([['path' => $path, 'find' => 'a', 'replace' => 'b']]), $this->root);
                self::fail('Expected a refusal for ' . $path);
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('regular file', $exception->getMessage());
            }
        }
    }

    public function testSymlinkedDirectoryCannotReachOutsideTheRoot(): void
    {
        $outside = sys_get_temp_dir() . '/agent-edit-direct-outside-' . bin2hex(random_bytes(4));
        mkdir($outside);
        file_put_contents($outside . '/secret.txt', "token\n");
        symlink($outside, $this->root . '/escape');

        try {
            (new SourceEditPlanApplier())->preflight($this->plan([['path' => 'escape/secret.txt', 'find' => 'token', 'replace' => 'x']]), $this->root);
            self::fail('A symlinked directory must not reach outside the root.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('outside the project root', $exception->getMessage());
        } finally {
            unlink($outside . '/secret.txt');
            rmdir($outside);
        }
    }

    public function testSyntaxBreakingEditIsRefusedAndNothingChanges(): void
    {
        try {
            (new SourceEditPlanApplier())->apply($this->plan([
                ['path' => 'src/Service.php', 'find' => 'return $a * 2;', 'replace' => 'return $a * ;'],
            ]), $this->root);
            self::fail('A syntax error must refuse the edit.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('syntax validation', $exception->getMessage());
            self::assertStringContainsString('src/Service.php', $exception->getMessage());
        }

        self::assertSame(self::SERVICE, $this->read('src/Service.php'));
        self::assertSame([], $this->temporaryArtifacts());
    }

    public function testASyntaxErrorInOneFileKeepsEveryOtherFileUntouched(): void
    {
        try {
            (new SourceEditPlanApplier())->apply($this->plan([
                ['path' => 'README.md', 'find' => 'Demo', 'replace' => 'Changed'],
                ['path' => 'src/Other.php', 'find' => 'final class Other', 'replace' => 'final class'],
            ]), $this->root);
            self::fail('A syntax error must refuse the whole plan.');
        } catch (RuntimeException) {
        }

        self::assertStringStartsWith('# Demo', $this->read('README.md'));
        self::assertStringContainsString('final class Other', $this->read('src/Other.php'));
    }

    public function testSignatureChangeIsRefusedWithGuidanceAndNothingChanges(): void
    {
        try {
            (new SourceEditPlanApplier())->apply($this->plan([
                ['path' => 'src/Service.php', 'find' => 'public function double(int $a): int', 'replace' => 'public function double(int $a, int $factor = 2): int'],
            ]), $this->root);
            self::fail('An API change needs caller evidence.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('method `Demo\Service::double` signature changed', $exception->getMessage());
            self::assertStringContainsString('agent-map plan', $exception->getMessage());
            self::assertStringContainsString('allow_signature_change', $exception->getMessage());
        }

        self::assertSame(self::SERVICE, $this->read('src/Service.php'));
    }

    public function testRenamingAMethodIsAnApiChangeToo(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('method `Demo\Service::double` was removed or renamed');

        (new SourceEditPlanApplier())->preflight($this->plan([
            ['path' => 'src/Service.php', 'find' => 'function double(', 'replace' => 'function twice('],
        ]), $this->root);
    }

    public function testAcknowledgedSignatureChangeIsAppliedAndRecorded(): void
    {
        $result = (new SourceEditPlanApplier())->apply($this->plan([
            ['path' => 'src/Service.php', 'find' => 'public function double(int $a): int', 'replace' => 'public function double(int $a, int $factor = 2): int'],
        ], [], true), $this->root);

        self::assertStringContainsString('int $factor = 2', $this->read('src/Service.php'));
        self::assertCount(1, $result->evidence['acknowledged_api_changes']);
        self::assertStringContainsString('src/Service.php: method `Demo\Service::double` signature changed', $result->evidence['acknowledged_api_changes'][0]);
    }

    public function testBodyEditsPrivateMethodChangesAndNewMembersAreNotApiChanges(): void
    {
        $result = (new SourceEditPlanApplier())->apply($this->plan([
            ['path' => 'src/Service.php', 'find' => 'private function helper(int $a): int', 'replace' => 'private function helper(string $renamed, int $more = 1): string'],
            ['path' => 'src/Service.php', 'find' => 'return $a;', 'replace' => 'return (string) $renamed;'],
            ['path' => 'src/Service.php', 'find' => "    public function double", 'replace' => "    public function triple(int \$a): int\n    {\n        return \$a * 3;\n    }\n\n    public function double"],
        ]), $this->root);

        self::assertSame([], $result->evidence['acknowledged_api_changes']);
        self::assertStringContainsString('function triple', $this->read('src/Service.php'));
    }

    public function testNonPhpFilesAreNeverSignatureCheckedOrSyntaxChecked(): void
    {
        file_put_contents($this->root . '/snippet.md', "```php\npublic function a(): void {}\n```\n");

        (new SourceEditPlanApplier())->apply($this->plan([
            ['path' => 'snippet.md', 'find' => 'public function a(): void {}', 'replace' => 'public function b(int $x): int {'],
        ]), $this->root);

        self::assertStringContainsString('function b(int $x)', $this->read('snippet.md'));
    }

    public function testCreatesNewFilesIncludingNestedDirectories(): void
    {
        $result = (new SourceEditPlanApplier())->apply($this->plan([], [
            ['path' => 'tests/Unit/ServiceTest.php', 'content' => "<?php\n\nfinal class ServiceTest\n{\n}\n"],
            ['path' => 'docs/notes.md', 'content' => "notes\n"],
        ]), $this->root);

        self::assertFileExists($this->root . '/tests/Unit/ServiceTest.php');
        self::assertSame("notes\n", $this->read('docs/notes.md'));
        self::assertNull($result->evidence['files'][0]['before_sha256']);
        self::assertSame([], $result->evidence['pre_images']);
        self::assertSame([], $this->temporaryArtifacts());
    }

    public function testCreatingAnExistingFileIsRefused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already exists');

        (new SourceEditPlanApplier())->preflight($this->plan([], [['path' => 'README.md', 'content' => 'x']]), $this->root);
    }

    public function testCreatedPhpIsSyntaxCheckedAndAFailureRemovesNewDirectories(): void
    {
        try {
            (new SourceEditPlanApplier())->apply($this->plan([
                ['path' => 'README.md', 'find' => 'Demo', 'replace' => 'Changed'],
            ], [['path' => 'brand/new/Broken.php', 'content' => "<?php\nfinal class {\n"]]), $this->root);
            self::fail('Broken created PHP must refuse the plan.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('brand/new/Broken.php', $exception->getMessage());
        }

        self::assertDirectoryDoesNotExist($this->root . '/brand');
        self::assertStringStartsWith('# Demo', $this->read('README.md'));
    }

    public function testInjectedPublicationFailureRestoresEverything(): void
    {
        // Fails publishing the last file, after extra/Made.php and README.md were already published.
        $applier = new SourceEditPlanApplier(
            static fn (string $from, string $to): bool => str_ends_with($to, '/src/Other.php') && str_contains($from, '-stage-') ? false : rename($from, $to),
        );

        try {
            $applier->apply($this->plan([
                ['path' => 'README.md', 'find' => 'Demo', 'replace' => 'Changed'],
                ['path' => 'src/Other.php', 'find' => 'namespace Demo;', 'replace' => 'namespace Demo; // touched'],
            ], [['path' => 'extra/Made.php', 'content' => "<?php\n"]]), $this->root);
            self::fail('The injected failure must surface.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('every file was restored', $exception->getMessage());
        }

        self::assertStringStartsWith('# Demo', $this->read('README.md'));
        self::assertStringNotContainsString('touched', $this->read('src/Other.php'));
        self::assertDirectoryDoesNotExist($this->root . '/extra');
        self::assertSame([], $this->temporaryArtifacts());
    }

    public function testPreflightIsPureAndReportsTheFinalContents(): void
    {
        $prepared = (new SourceEditPlanApplier())->preflight($this->plan([
            ['path' => 'src/Service.php', 'find' => 'return $a - $b;', 'replace' => 'return $a + $b;'],
        ], [['path' => 'docs/new.md', 'content' => "hello\n"]]), $this->root);

        self::assertSame('source_edit_plan', $prepared['plan_type']);
        self::assertSame(1, $prepared['edit_count']);
        self::assertSame(1, $prepared['creation_count']);
        self::assertSame(0, $prepared['move_count']);
        self::assertCount(2, $prepared['files']);
        $service = $this->root . '/src/Service.php';
        self::assertStringContainsString('return $a + $b;', $prepared['files'][realpath($this->root) . '/src/Service.php']);
        self::assertSame('sha256:' . hash('sha256', self::SERVICE), $prepared['source_hashes'][realpath($this->root) . '/src/Service.php']);
        self::assertSame(self::SERVICE, $this->read('src/Service.php'));
        self::assertFileDoesNotExist($this->root . '/docs/new.md');
        self::assertFileExists($service);
        self::assertSame([], $this->temporaryArtifacts());
    }

    public function testRejectsAPlanOfAnotherType(): void
    {
        $this->expectException(RuntimeException::class);

        (new SourceEditPlanApplier())->apply(['type' => 'method_removal_plan', 'contract_version' => '1.0'], $this->root);
    }

    private function read(string $relative): string
    {
        return (string) file_get_contents($this->root . '/' . $relative);
    }

    /** @return list<string> */
    private function temporaryArtifacts(): array
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
