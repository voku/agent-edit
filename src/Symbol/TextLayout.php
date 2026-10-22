<?php

declare(strict_types=1);

namespace voku\AgentEdit\Symbol;

/**
 * Line, indentation and line-ending helpers so code written by a coding agent fits the file it lands in: the code's
 * own common indentation is removed, the target indentation is applied and the file's line ending is kept.
 */
final readonly class TextLayout
{
    public function __construct(private string $content)
    {
    }

    /** The file's dominant line ending; files without line breaks default to "\n". */
    public function eol(): string
    {
        $crlf = substr_count($this->content, "\r\n");
        $lf = substr_count($this->content, "\n") - $crlf;

        return $crlf > $lf ? "\r\n" : "\n";
    }

    public function lineStart(int $position): int
    {
        $previous = strrpos(substr($this->content, 0, $position), "\n");

        return $previous === false ? 0 : $previous + 1;
    }

    /** Offset just after the line break that ends the line containing `$position` (or the end of the content). */
    public function nextLineStart(int $position): int
    {
        $next = strpos($this->content, "\n", $position);

        return $next === false ? strlen($this->content) : $next + 1;
    }

    /** The whitespace before `$position` on its line, or '' when other text precedes it there. */
    public function indentAt(int $position): string
    {
        $prefix = substr($this->content, $this->lineStart($position), $position - $this->lineStart($position));

        return trim($prefix, " \t") === '' ? $prefix : '';
    }

    public function onlyWhitespaceBefore(int $position): bool
    {
        return trim(substr($this->content, $this->lineStart($position), $position - $this->lineStart($position)), " \t") === '';
    }

    /** One level of indentation: the first indented line's leading whitespace, defaulting to four spaces. */
    public function indentUnit(): string
    {
        if (preg_match('/^(\t+|[ ]+)\S/m', $this->content, $matches) === 1) {
            return $matches[1][0] === "\t" ? "\t" : (strlen($matches[1]) % 4 === 0 ? '    ' : $matches[1]);
        }

        return '    ';
    }

    public function lineNumber(int $position): int
    {
        return substr_count(substr($this->content, 0, $position), "\n") + 1;
    }

    /** The line that contains `$position`, without its line break. */
    public function lineAt(int $position): string
    {
        $start = $this->lineStart($position);

        return rtrim(substr($this->content, $start, $this->nextLineStart($position) - $start), "\r\n");
    }

    /**
     * Removes surrounding blank lines and the common leading indentation from agent-written code, then indents every
     * line but the first with `$indent` (the first line continues where the replaced text started) and converts line
     * breaks to `$eol`.
     */
    public static function reindent(string $code, string $indent, string $eol, bool $indentFirstLine = false): string
    {
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $code));
        while ($lines !== [] && trim($lines[0]) === '') {
            array_shift($lines);
        }
        while ($lines !== [] && trim($lines[count($lines) - 1]) === '') {
            array_pop($lines);
        }
        if ($lines === []) {
            return '';
        }

        $common = null;
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $leading = substr($line, 0, strspn($line, " \t"));
            $common = $common === null ? $leading : self::commonPrefix($common, $leading);
        }
        $common ??= '';

        $result = [];
        foreach ($lines as $index => $line) {
            if (trim($line) === '') {
                $result[] = '';
                continue;
            }
            $line = substr($line, strlen($common));
            $result[] = ($index === 0 && !$indentFirstLine ? '' : $indent) . $line;
        }

        return implode($eol, $result);
    }

    private static function commonPrefix(string $left, string $right): string
    {
        $length = min(strlen($left), strlen($right));
        $index = 0;
        while ($index < $length && $left[$index] === $right[$index]) {
            ++$index;
        }

        return substr($left, 0, $index);
    }
}
