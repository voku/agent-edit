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

    public function testLineBreaksInsideAStringFollowTheFileLineEnding(): void
    {
        self::assertSame(
            "function f(): string\r\n{\r\n    return 'a\r\nb';\r\n}",
            TextLayout::reindent("function f(): string\n{\n    return 'a\nb';\n}", '', "\r\n"),
        );
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function valuePreservingSnippets(): iterable
    {
        $any = ['', '    ', "\t\t"];
        yield 'single quoted' => ["function f(): string\n{\n    return 'a\n  b\n\tc';\n}", $any];
        yield 'double quoted' => ["function f(): string\n{\n    \$x = 'v';\n    return \"a \$x\n  b {\$x}\n c\";\n}", $any];
        yield 'backtick in a comment is not a string' => ["function f(): string\n{\n    // `not a string\n    return 'ok';\n}", $any];
        // A heredoc marker indented with tabs over space-indented code would mix both in one marker; PHP refuses that.
        yield 'string after a heredoc' => ["function f(): string\n{\n    \$h = <<<TXT\n    in\n    TXT;\n\n    return \$h . 'a\n b';\n}", ['', '    ']];
        yield 'heredoc nested in a heredoc interpolation' => ["function f(): string\n{\n    \$a = ['key' => 'v'];\n    return <<<OUTER\n    before\n    {\$a[<<<INNER\n    key\n    INNER]}\n    after\n    OUTER;\n}", ['', '    ']];
        yield 'escaped quotes' => ["function f(): string\n{\n    return 'it\\'s\n  here' . \"say \\\"hi\\\"\n  now\";\n}", $any];
    }

    /** @param list<string> $indents */
    #[DataProvider('valuePreservingSnippets')]
    public function testReindentNeverChangesWhatTheCodeEvaluatesTo(string $code, array $indents): void
    {
        foreach ($indents as $indent) {
            self::assertSame(
                self::evaluate($code),
                self::evaluate(TextLayout::reindent($code, $indent, "\n")),
                sprintf('indent %s', var_export($indent, true)),
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
