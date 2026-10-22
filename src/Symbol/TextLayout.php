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
     *
     * With `$unit` (one level of the target file's indentation, see {@see indentUnit()}) the code's own nesting is
     * written in that style too: code indented with four spaces lands as tabs in a tab-indented file instead of mixing
     * both. Heredoc and nowdoc bodies are never converted.
     */
    public static function reindent(string $code, string $indent, string $eol, bool $indentFirstLine = false, ?string $unit = null): string
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $code);
        $lines = explode("\n", $normalized);
        [$modes, $markers] = self::lineModes($normalized);

        $skipped = 0; // leading blank lines removed: `$markers` are indexed by the original lines
        while ($lines !== [] && trim($lines[0]) === '') {
            array_shift($lines);
            $modes = array_slice($modes, 1);
            ++$skipped;
        }
        while ($lines !== [] && trim($lines[count($lines) - 1]) === '') {
            array_pop($lines);
            array_pop($modes);
        }
        if ($lines === []) {
            return '';
        }

        $common = null;
        foreach ($lines as $index => $line) {
            if (trim($line) === '' || $modes[$index] === self::STRING) {
                continue;
            }
            $leading = substr($line, 0, strspn($line, " \t"));
            $common = $common === null ? $leading : self::commonPrefix($common, $leading);
        }
        $common ??= '';

        $codeUnit = $unit === null ? null : self::codeUnit($lines, $modes, strlen($common));
        $convert = $unit !== null && $codeUnit !== null && $codeUnit !== $unit;

        $result = [];
        foreach ($lines as $index => $line) {
            if ($modes[$index] === self::STRING) {
                $result[] = $line;
                continue;
            }
            if (trim($line) === '' && $modes[$index] === self::CODE) {
                $result[] = '';
                continue;
            }
            $line = substr($line, strlen($common));
            if ($convert && $modes[$index] === self::CODE) {
                $line = self::convertIndentation($line, (string) $codeUnit, (string) $unit);
            } elseif ($convert && isset($markers[$index + $skipped])) {
                $line = self::convertHeredocLine($line, substr($lines[$markers[$index + $skipped] - $skipped], strlen($common)), (string) $codeUnit, (string) $unit);
            }
            $result[] = ($index === 0 && !$indentFirstLine ? '' : $indent) . $line;
        }

        return implode($eol, $result);
    }

    private const CODE = 0;
    private const STRING = 1;
    private const HEREDOC = 2;

    /**
     * One level of the code's own indentation after the common part is removed: a tab when any code line is indented
     * with one, else the smallest space indentation (comment continuation lines ` * text` are ignored). Null when no
     * line is indented.
     *
     * @param list<string> $lines
     * @param list<int> $modes
     */
    private static function codeUnit(array $lines, array $modes, int $common): ?string
    {
        $smallest = null;
        foreach ($lines as $index => $line) {
            if ($modes[$index] !== self::CODE) {
                continue;
            }
            $line = substr($line, $common);
            if (trim($line) === '' || ltrim($line)[0] === '*') {
                continue;
            }
            $leading = substr($line, 0, strspn($line, " \t"));
            if ($leading === '') {
                continue;
            }
            if ($leading[0] === "\t") {
                return "\t";
            }
            $smallest = $smallest === null ? strlen($leading) : min($smallest, strlen($leading));
        }

        return $smallest === null ? null : str_repeat(' ', $smallest);
    }

    /**
     * A heredoc body line starts with the closing marker's indentation, which PHP removes; only that prefix is layout
     * (the rest is content) and, like the marker's own, it must not mix tabs and spaces.
     */
    private static function convertHeredocLine(string $line, string $markerLine, string $codeUnit, string $unit): string
    {
        $markerIndent = substr($markerLine, 0, strspn($markerLine, " \t"));
        if ($markerIndent === '' || !str_starts_with($line, $markerIndent)) {
            return $line;
        }

        return self::convertIndentation($markerIndent, $codeUnit, $unit) . substr($line, strlen($markerIndent));
    }

    /** Rewrites the leading whole levels of `$codeUnit` as `$unit`; alignment spaces beyond them stay. */
    private static function convertIndentation(string $line, string $codeUnit, string $unit): string
    {
        $length = strlen($codeUnit);
        $levels = 0;
        while (substr($line, $levels * $length, $length) === $codeUnit) {
            ++$levels;
        }

        return str_repeat($unit, $levels) . substr($line, $levels * $length);
    }

    /**
     * For every line of `$code` (split on "\n"): CODE, STRING when it starts inside a quoted string literal (a
     * single-quoted, double-quoted or backtick string that continues over a line break) or HEREDOC when it starts
     * inside a heredoc or nowdoc, whose indentation is governed by its closing marker.
     *
     * @return array{list<int>, array<int, int>} the modes and, for every line of a simple (not nested) heredoc body, the
     *         line of its closing marker
     */
    private static function lineModes(string $code): array
    {
        $lines = substr_count($code, "\n") + 1;
        $modes = array_fill(0, $lines, self::CODE);
        if (!str_contains($code, "\n") || !class_exists(\PhpToken::class)) {
            return [$modes, []];
        }
        /** @var array<int, int> $markers */
        $markers = [];
        $bodyStart = null;

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
                $bodyStart = $heredocDepth === 1 ? $line + 1 : null;
            } elseif ($token->id === T_END_HEREDOC) {
                if ($heredocDepth === 1 && $bodyStart !== null) {
                    for ($body = $bodyStart; $body <= $line && $body < $lines; ++$body) {
                        $markers[$body] = $line;
                    }
                }
                $heredocDepth = max(0, $heredocDepth - 1);
            } elseif ($heredocDepth === 0 && ($token->text === '"' || $token->text === '`')) {
                $quoted = !$quoted;
            }

            $breaks = substr_count($token->text, "\n");
            if ($breaks > 0) {
                $mode = $heredocDepth > 0 ? self::HEREDOC : (($quoted || $token->id === T_CONSTANT_ENCAPSED_STRING || $token->id === T_ENCAPSED_AND_WHITESPACE) ? self::STRING : self::CODE);
                for ($offset = 1; $offset <= $breaks && $line + $offset < $lines; ++$offset) {
                    $modes[$line + $offset] = $mode;
                }
            }
            $line += $breaks;
        }

        return [$modes, $markers];
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
