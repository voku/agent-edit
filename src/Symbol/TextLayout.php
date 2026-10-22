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
     *
     * A line that starts inside a quoted string literal is string content, not code layout: it is neither dedented nor
     * indented (and does not count towards the common indentation), so re-indenting never changes a value. Heredoc and
     * nowdoc bodies are indented together with their closing marker, which PHP removes again.
     */
    public static function reindent(string $code, string $indent, string $eol, bool $indentFirstLine = false): string
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $code);
        $lines = explode("\n", $normalized);
        $verbatim = self::linesInsideStrings($normalized);

        while ($lines !== [] && trim($lines[0]) === '') {
            array_shift($lines);
            $verbatim = array_slice($verbatim, 1);
        }
        while ($lines !== [] && trim($lines[count($lines) - 1]) === '') {
            array_pop($lines);
            array_pop($verbatim);
        }
        if ($lines === []) {
            return '';
        }

        $common = null;
        foreach ($lines as $index => $line) {
            if (trim($line) === '' || $verbatim[$index]) {
                continue;
            }
            $leading = substr($line, 0, strspn($line, " \t"));
            $common = $common === null ? $leading : self::commonPrefix($common, $leading);
        }
        $common ??= '';

        $result = [];
        foreach ($lines as $index => $line) {
            if ($verbatim[$index]) {
                $result[] = $line;
                continue;
            }
            if (trim($line) === '') {
                $result[] = '';
                continue;
            }
            $line = substr($line, strlen($common));
            $result[] = ($index === 0 && !$indentFirstLine ? '' : $indent) . $line;
        }

        return implode($eol, $result);
    }

    /**
     * For every line of `$code` (split on "\n"): whether it starts inside a quoted string literal, that is a
     * single-quoted, double-quoted or backtick string that continues over a line break. Heredoc and nowdoc bodies are
     * not included; their indentation is governed by the closing marker.
     *
     * @return list<bool>
     */
    private static function linesInsideStrings(string $code): array
    {
        $lines = substr_count($code, "\n") + 1;
        $inside = array_fill(0, $lines, false);
        if (!str_contains($code, "\n") || !class_exists(\PhpToken::class)) {
            return $inside;
        }

        $line = 0;
        $heredocDepth = 0;
        $quoted = false;
        $first = true;
        foreach (\PhpToken::tokenize("<?php\n" . $code) as $token) {
            if ($first) {
                $first = false; // the "<?php\n" prefix
                continue;
            }
            if ($token->id === T_START_HEREDOC) {
                ++$heredocDepth; // a heredoc can nest in another one's `{$a[<<<X ... X]}` interpolation
            } elseif ($token->id === T_END_HEREDOC) {
                $heredocDepth = max(0, $heredocDepth - 1);
            } elseif ($heredocDepth === 0 && ($token->text === '"' || $token->text === '`')) {
                $quoted = !$quoted;
            }

            $breaks = substr_count($token->text, "\n");
            if ($breaks > 0 && $heredocDepth === 0 && $token->id !== T_START_HEREDOC) {
                $spansLines = $quoted || $token->id === T_CONSTANT_ENCAPSED_STRING || $token->id === T_ENCAPSED_AND_WHITESPACE;
                if ($spansLines) {
                    for ($offset = 1; $offset <= $breaks && $line + $offset < $lines; ++$offset) {
                        $inside[$line + $offset] = true;
                    }
                }
            }
            $line += $breaks;
        }

        return array_values($inside);
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
