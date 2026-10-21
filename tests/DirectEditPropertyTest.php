<?php

declare(strict_types=1);

namespace voku\AgentEdit\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentEdit\Apply\SourceEditPlanApplier;
use voku\AgentEdit\EditEngine;
use voku\AgentEdit\Receipt\ApplyRequest;

/**
 * Seeded, reproducible properties of direct edits over awkward bytes (CRLF, tabs, multibyte text, no trailing newline,
 * replacement text full of regex and shell metacharacters). A failure names its seed.
 */
final class DirectEditPropertyTest extends TestCase
{
    /** Replacement fragments that are valid inside a PHP single-quoted string or comment. */
    private const SAFE = ['', 'x', ' ', 'größe', '日本語', '$1 \\1 %s {0}', '*/ no', "tab\there", '#', 'a b c', '..', '0'];

    /** Fragments that break out of the surrounding string literal and therefore break PHP syntax. */
    private const UNSAFE = ["';\n}}} garbage(", "'; }", "\n?>\n<?php if ("];

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-edit-prop-' . bin2hex(random_bytes(6));
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

    public function testApplyEqualsIndependentReplacementAndUndoRestoresTheExactBytes(): void
    {
        for ($seed = 1; $seed <= 120; ++$seed) {
            mt_srand($seed);
            $markers = mt_rand(2, 9);
            $content = $this->content($markers);
            file_put_contents($this->root . '/Subject.php', $content);

            $chosen = array_values(array_filter(range(0, $markers - 1), static fn (): bool => mt_rand(0, 2) > 0));
            if ($chosen === []) {
                $chosen = [0];
            }
            $expected = $content;
            $edits = [];
            foreach ($chosen as $index) {
                $replacement = self::SAFE[mt_rand(0, count(self::SAFE) - 1)];
                $find = 'marker' . $index . 'END';
                $edits[] = ['path' => 'Subject.php', 'find' => $find, 'replace' => $replacement];
                $expected = str_replace($find, $replacement, $expected);
            }
            shuffle($edits);

            $label = 'seed' . $seed;
            $this->applyWithReceipt($edits, $label);
            self::assertSame($expected, file_get_contents($this->root . '/Subject.php'), $label . ': result differs from independent replacement');

            (new EditEngine())->undo($this->root, '.agent-edit/receipts/' . $label);
            self::assertSame($content, file_get_contents($this->root . '/Subject.php'), $label . ': undo did not restore the exact bytes');
            self::assertSame([], $this->temporaryArtifacts(), $label);
        }
    }

    public function testReplacementsThatBreakSyntaxAreRefusedUntouchedAndOnlyValidPhpIsEverPublished(): void
    {
        $refused = 0;
        for ($seed = 1; $seed <= 40; ++$seed) {
            mt_srand(1000 + $seed);
            $content = $this->content(4);
            file_put_contents($this->root . '/Subject.php', $content);
            $edit = ['path' => 'Subject.php', 'find' => 'marker' . mt_rand(0, 3) . 'END', 'replace' => self::UNSAFE[mt_rand(0, count(self::UNSAFE) - 1)]];

            try {
                (new SourceEditPlanApplier())->apply($this->plan([$edit]), $this->root);
                // A fragment can by chance re-balance the quotes into valid PHP; then publishing it is correct,
                // but only if PHP itself agrees the published file parses.
                self::assertSame(0, $this->lint($this->root . '/Subject.php'), 'seed ' . $seed . ': published PHP must parse');
            } catch (RuntimeException $exception) {
                ++$refused;
                self::assertStringContainsString('syntax validation', $exception->getMessage(), 'seed ' . $seed);
                self::assertSame($content, file_get_contents($this->root . '/Subject.php'), 'seed ' . $seed . ': refusal must leave the bytes identical');
            }
            self::assertSame([], $this->temporaryArtifacts(), 'seed ' . $seed);
        }

        self::assertGreaterThan(20, $refused, 'The property would be vacuous if almost nothing were refused.');
    }

    private function lint(string $path): int
    {
        exec(escapeshellarg(PHP_BINARY) . ' -n -l ' . escapeshellarg($path) . ' 2>&1', $output, $code);

        return $code;
    }

    public function testAnAnchorSpanningTheWholeFileAndAnAnchorAtTheEdgesWork(): void
    {
        $content = "<?php\n// head\n\$a = 1;\n// tail";
        file_put_contents($this->root . '/Edge.php', $content);

        (new SourceEditPlanApplier())->apply($this->plan([
            ['path' => 'Edge.php', 'find' => '<?php', 'replace' => '<?php declare(strict_types=1);'],
            ['path' => 'Edge.php', 'find' => '// tail', 'replace' => '// end'],
        ]), $this->root);
        self::assertSame("<?php declare(strict_types=1);\n// head\n\$a = 1;\n// end", file_get_contents($this->root . '/Edge.php'));

        $whole = (string) file_get_contents($this->root . '/Edge.php');
        (new SourceEditPlanApplier())->apply($this->plan([
            ['path' => 'Edge.php', 'find' => $whole, 'replace' => "<?php\n\$b = 2;\n"],
        ]), $this->root);
        self::assertSame("<?php\n\$b = 2;\n", file_get_contents($this->root . '/Edge.php'));
    }

    public function testAFileWithoutTrailingNewlineAndABomKeepsItsBytes(): void
    {
        file_put_contents($this->root . '/Bom.txt', "\xEF\xBB\xBFtitle\nbody");

        (new SourceEditPlanApplier())->apply($this->plan([
            ['path' => 'Bom.txt', 'find' => 'body', 'replace' => 'text'],
        ]), $this->root);

        self::assertSame("\xEF\xBB\xBFtitle\ntext", file_get_contents($this->root . '/Bom.txt'));
    }

    public function testManyEditsAcrossManyFilesApplyAndUndoToAnIdenticalTree(): void
    {
        $edits = [];
        for ($file = 0; $file < 25; ++$file) {
            $body = '';
            for ($line = 0; $line < 20; ++$line) {
                $body .= sprintf("\$v%d_%d = 'value-%d-%d';\n", $file, $line, $file, $line);
            }
            file_put_contents($this->root . '/f' . $file . '.php', "<?php\n\n" . $body);
            for ($line = 0; $line < 20; $line += 4) {
                $edits[] = ['path' => 'f' . $file . '.php', 'find' => sprintf("'value-%d-%d'", $file, $line), 'replace' => sprintf("'changed-%d-%d'", $file, $line)];
            }
        }
        $before = $this->treeDigest();

        $this->applyWithReceipt($edits, 'bulk');
        self::assertNotSame($before, $this->treeDigest());
        self::assertStringContainsString("'changed-24-16'", (string) file_get_contents($this->root . '/f24.php'));

        (new EditEngine())->undo($this->root, '.agent-edit/receipts/bulk');
        self::assertSame($before, $this->treeDigest());
    }

    public function testALargeFileIsEditedQuickly(): void
    {
        $lines = [];
        for ($index = 0; $index < 60000; ++$index) {
            $lines[] = '// line ' . $index . ' ' . str_repeat('x', 40);
        }
        file_put_contents($this->root . '/Big.php', "<?php\n" . implode("\n", $lines) . "\n");
        $started = microtime(true);

        (new SourceEditPlanApplier())->apply($this->plan([
            ['path' => 'Big.php', 'find' => '// line 59999 ', 'replace' => '// LAST '],
            ['path' => 'Big.php', 'find' => '// line 30000 ', 'replace' => '// MIDDLE '],
        ]), $this->root);

        self::assertLessThan(5.0, microtime(true) - $started);
        self::assertStringContainsString('// LAST ', (string) file_get_contents($this->root . '/Big.php'));
        self::assertStringContainsString('// MIDDLE ', (string) file_get_contents($this->root . '/Big.php'));
    }

    private function content(int $markers): string
    {
        $newline = mt_rand(0, 3) === 0 ? "\r\n" : "\n";
        $indent = mt_rand(0, 2) === 0 ? "\t" : '    ';
        $lines = ['<?php', '', 'declare(strict_types=1);', '', 'final class Subject', '{'];
        for ($index = 0; $index < $markers; ++$index) {
            $lines[] = $indent . 'public function m' . $index . '(): string';
            $lines[] = $indent . '{';
            $lines[] = $indent . $indent . "return 'größe 日本 marker" . $index . "END';";
            $lines[] = $indent . '}';
            $lines[] = '';
        }
        $lines[] = '}';

        return implode($newline, $lines) . (mt_rand(0, 3) === 0 ? '' : $newline);
    }

    /**
     * @param list<array<string, mixed>> $edits
     * @return array<string, mixed>
     */
    private function plan(array $edits): array
    {
        return ['type' => 'source_edit_plan', 'contract_version' => '1.0', 'target_id' => 'property', 'edits' => $edits];
    }

    /** @param list<array<string, mixed>> $edits */
    private function applyWithReceipt(array $edits, string $label): void
    {
        $dir = $this->root . '/.agent-edit/receipts/' . $label;
        mkdir($dir, 0o775, true);
        file_put_contents($dir . '/plan.json', json_encode($this->plan($edits), JSON_THROW_ON_ERROR));
        (new EditEngine())->applyWithReceipt(new ApplyRequest($this->root, $dir . '/plan.json', null, $this->root, $dir, $label));
    }

    private function treeDigest(): string
    {
        $entries = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($iterator as $item) {
            $path = str_replace('\\', '/', $item->getPathname());
            if (str_contains($path, '/.agent-edit/')) {
                continue;
            }
            $entries[$path] = hash_file('sha256', $path);
        }
        ksort($entries);

        return hash('sha256', (string) json_encode($entries));
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
