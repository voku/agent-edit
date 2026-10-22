<?php

declare(strict_types=1);

namespace voku\AgentEdit\Tests\Symbol;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use voku\AgentEdit\Symbol\TextLayout;

final class TextLayoutTest extends TestCase
{
    public function testIndentsCodeAndRemovesItsCommonIndentation(): void
    {
        self::assertSame(
            "    if (\$a) {\n        return 1;\n    }",
            TextLayout::reindent("  if (\$a) {\n      return 1;\n  }", '    ', "\n", true),
        );
    }

    public function testAMultiLineSingleQuotedStringKeepsItsValue(): void
    {
        $code = "public function text(): string\n{\n    return 'line1\n  line2\nline3';\n}";

        self::assertSame(
            "public function text(): string\n    {\n        return 'line1\n  line2\nline3';\n    }",
            TextLayout::reindent($code, '    ', "\n"),
        );
    }

    public function testAMultiLineDoubleQuotedStringWithInterpolationKeepsItsValue(): void
    {
        $code = "    function f(array \$a): string\n    {\n        return \"x {\$a[\"k\"]}\n  y \$a[0]\n z\";\n    }";

        self::assertSame(
            "function f(array \$a): string\n{\n    return \"x {\$a[\"k\"]}\n  y \$a[0]\n z\";\n}",
            TextLayout::reindent($code, '', "\n", true),
            'only the code lines move; the string continuation lines stay where they are',
        );
    }

    public function testAWhitespaceOnlyLineInsideAStringIsNotBlanked(): void
    {
        $result = TextLayout::reindent("function f(): string\n{\n    return 'a\n   \nb';\n}", '    ', "\n");

        self::assertStringContainsString("'a\n   \nb'", $result);
    }

    public function testStringLinesDoNotShrinkTheCommonIndentation(): void
    {
        $code = "    function f(): string\n    {\n        return 'a\nb';\n    }";

        self::assertSame(
            "function f(): string\n{\n    return 'a\nb';\n}",
            TextLayout::reindent($code, '', "\n", true),
        );
    }

    public function testHeredocAndNowdocBodiesMoveWithTheirClosingMarker(): void
    {
        $code = "function f(): string\n{\n    return <<<TXT\n    hello\n      world\n    TXT . <<<'RAW'\n    raw\n    RAW;\n}";
        $result = TextLayout::reindent($code, '        ', "\n");

        self::assertSame(
            "function f(): string\n        {\n            return <<<TXT\n            hello\n              world\n            TXT . <<<'RAW'\n            raw\n            RAW;\n        }",
            $result,
        );
        self::assertSame(self::evaluate($code), self::evaluate($result));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function indentUnits(): iterable
    {
        yield 'four spaces' => ["<?php\nclass A\n{\n    public \$x;\n}\n", '    '];
        yield 'two spaces' => ["<?php\nclass A\n{\n  public \$x;\n}\n", '  '];
        yield 'tab' => ["<?php\nclass A\n{\n\tpublic \$x;\n}\n", "\t"];
        yield 'nothing indented' => ['<?php class A {}', '    '];
        yield 'file docblock first' => ["<?php\n\n/**\n * File doc.\n *\n * @package x\n */\nclass A\n{\n    public \$x;\n}\n", '    '];
        yield 'file docblock first, tabs' => ["<?php\n\n/**\n * File doc.\n */\nclass A\n{\n\tpublic \$x;\n}\n", "\t"];
        yield 'file docblock first, crlf' => ["<?php\r\n/**\r\n * Doc.\r\n */\r\nclass A\r\n{\r\n    public \$x;\r\n}\r\n", '    '];
        yield 'multi-line string first' => ["<?php\n\$sql = 'select\n   a\n  from b';\nclass A\n{\n    public \$x;\n}\n", '    '];
        yield 'heredoc first' => ["<?php\n\$t = <<<TXT\n   odd\nTXT;\nclass A\n{\n\tpublic \$x;\n}\n", "\t"];
    }

    #[DataProvider('indentUnits')]
    public function testIndentUnitIsTheCodesFirstIndentNotACommentOrStringLine(string $source, string $expected): void
    {
        self::assertSame($expected, (new TextLayout($source))->indentUnit());
    }

    public function testSpaceIndentedCodeLandsAsTabsInATabIndentedFile(): void
    {
        $code = "public function f(array \$a): int\n{\n    if (\$a) {\n        return [\n            1,\n        ][0];\n    }\n\n    return 0;\n}";

        self::assertSame(
            "\tpublic function f(array \$a): int\n\t{\n\t\tif (\$a) {\n\t\t\treturn [\n\t\t\t\t1,\n\t\t\t][0];\n\t\t}\n\n\t\treturn 0;\n\t}",
            TextLayout::reindent($code, "\t", "\n", true, "\t"),
        );
    }

    public function testTabIndentedCodeLandsAsSpacesInASpaceIndentedFile(): void
    {
        self::assertSame(
            "    function f(): int\n    {\n        return 1;\n    }",
            TextLayout::reindent("function f(): int\n{\n\treturn 1;\n}", '    ', "\n", true, '    '),
        );
        self::assertSame(
            "  function f(): int\n  {\n    return 1;\n  }",
            TextLayout::reindent("function f(): int\n{\n    return 1;\n}", '  ', "\n", true, '  '),
            'a four-space code level becomes one two-space level',
        );
    }

    public function testAlignmentAndCommentContinuationLinesKeepTheirSpaces(): void
    {
        $code = "/**\n * Doc.\n */\nfunction f(int \$a,\n           int \$b): int\n{\n    return \$a\n        + \$b;\n}";

        $result = TextLayout::reindent($code, "\t", "\n", true, "\t");

        self::assertStringContainsString("\t/**\n\t * Doc.\n\t */\n", $result);
        self::assertStringContainsString("\n\t\t\t   int \$b): int\n", $result, 'the 11 alignment spaces keep their column: two levels and three spaces');
        self::assertStringContainsString("\n\t\treturn \$a\n\t\t\t+ \$b;\n", $result);
    }

    public function testNoConversionWithoutAUnitOrWithTheSameStyle(): void
    {
        $code = "function f(): int\n{\n    return 1;\n}";

        self::assertSame("function f(): int\n{\n    return 1;\n}", TextLayout::reindent($code, '', "\n"));
        self::assertSame("function f(): int\n{\n    return 1;\n}", TextLayout::reindent($code, '', "\n", false, '    '));
    }

    public function testHeredocPrefixIsConvertedAndItsContentIsNot(): void
    {
        $code = "function f(): string\n{\n    return <<<TXT\n    keep\n      this\n    TXT;\n}";

        $result = TextLayout::reindent($code, "\t", "\n", true, "\t");

        self::assertSame("\tfunction f(): string\n\t{\n\t\treturn <<<TXT\n\t\tkeep\n\t\t  this\n\t\tTXT;\n\t}", $result);
        self::assertSame("keep\n  this", self::evaluate($result));
    }

    public function testAFlushLeftHeredocIsLeftWhereItIs(): void
    {
        $code = "    public function f(): string\n    {\n        return <<<'TXT'\n<?php\n  keep\nTXT;\n    }";

        // Nothing to dedent and nothing to indent in the heredoc: its marker is at column 0.
        self::assertSame(
            "    public function f(): string\n    {\n        return <<<'TXT'\n<?php\n  keep\nTXT;\n    }",
            TextLayout::reindent($code, '    ', "\n", true, '    '),
        );
        self::assertSame(
            "public function f(): string\n{\n    return <<<'TXT'\n<?php\n  keep\nTXT;\n}",
            TextLayout::reindent($code, '', "\n", true, '    '),
            'the code around it is dedented, the heredoc is not',
        );
        self::assertSame("<?php\n  keep", self::evaluate("function f(): string\n{\n    return <<<'TXT'\n<?php\n  keep\nTXT;\n}"));
    }

    public function testAnIndentedHeredocMovesWithItsMarkerAsBefore(): void
    {
        $code = "    public function f(): string\n    {\n        return <<<TXT\n        a\n          b\n        TXT;\n    }";

        self::assertSame(
            "public function f(): string\n{\n    return <<<TXT\n    a\n      b\n    TXT;\n}",
            TextLayout::reindent($code, '', "\n", true),
        );
    }

    public function testLineBreaksInsideAStringFollowTheFileLineEnding(): void
    {
        self::assertSame(
            "function f(): string\r\n{\r\n    return 'a\r\nb';\r\n}",
            TextLayout::reindent("function f(): string\n{\n    return 'a\nb';\n}", '', "\r\n"),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function valuePreservingSnippets(): iterable
    {
        yield 'single quoted' => ["function f(): string\n{\n    return 'a\n  b\n\tc';\n}"];
        yield 'double quoted' => ["function f(): string\n{\n    \$x = 'v';\n    return \"a \$x\n  b {\$x}\n c\";\n}"];
        yield 'backtick in a comment is not a string' => ["function f(): string\n{\n    // `not a string\n    return 'ok';\n}"];
        yield 'string after a heredoc' => ["function f(): string\n{\n    \$h = <<<TXT\n    in\n      deeper\n    TXT;\n\n    return \$h . 'a\n b';\n}"];
        yield 'heredoc nested in a heredoc interpolation' => ["function f(): string\n{\n    \$a = ['key' => 'v'];\n    return <<<OUTER\n    before\n    {\$a[<<<INNER\n    key\n    INNER]}\n    after\n    OUTER;\n}"];
        yield 'escaped quotes' => ["function f(): string\n{\n    return 'it\\'s\n  here' . \"say \\\"hi\\\"\n  now\";\n}"];
    }

    #[DataProvider('valuePreservingSnippets')]
    public function testReindentNeverChangesWhatTheCodeEvaluatesTo(string $code): void
    {
        $targets = [['', null], ['    ', null], ['', "\t"], ["\t\t", "\t"], ['    ', '    '], ['  ', '  ']];
        if (!str_contains($code, '<<<')) {
            $targets[] = ["\t\t", null]; // a heredoc marker indented with tabs over space-indented code would mix both
        }
        if (substr_count($code, '<<<') > 1) {
            // Nested heredocs keep their prefix as written (they are refused by the syntax check when that mixes styles).
            $targets = [['', null], ['    ', null], ['    ', '    ']];
        }
        foreach ($targets as [$indent, $unit]) {
            self::assertSame(
                self::evaluate($code),
                self::evaluate(TextLayout::reindent($code, $indent, "\n", false, $unit)),
                sprintf('indent %s, unit %s', var_export($indent, true), var_export($unit, true)),
            );
        }
    }

    private static function evaluate(string $code): string
    {
        $name = 'reindent_probe_' . bin2hex(random_bytes(6));
        $code = preg_replace('/function f\(/', 'function ' . $name . '(', $code, 1);
        self::assertIsString($code);
        eval($code);

        self::assertIsCallable($name);

        return (string) $name();
    }
}
