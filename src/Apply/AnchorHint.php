<?php

declare(strict_types=1);

namespace voku\AgentEdit\Apply;

/**
 * Turns "the anchor did not match" into the one fact the author needs next: where the text really is, or how it differs.
 * Everything here is a deterministic string comparison; it never guesses an edit, it only points at evidence.
 */
final class AnchorHint
{
    private const MAX_LISTED = 5;
    private const EXCERPT = 100;

    public static function line(string $content, int $offset): int
    {
        return substr_count($content, "\n", 0, $offset) + 1;
    }

    /** @return list<int> byte offsets of non-overlapping exact matches */
    public static function offsets(string $content, string $find): array
    {
        $offsets = [];
        $length = strlen($find);
        for ($position = strpos($content, $find); $position !== false; $position = strpos($content, $find, $position + $length)) {
            $offsets[] = $position;
        }

        return $offsets;
    }

    /**
     * The `\r\n` spelling of a multi-line `find` written with bare `\n`, only when that is the file's own spelling:
     * every line break of `$content` is `\r\n` and `$find` has none. A file that mixes both has no spelling to adopt,
     * so no conversion is offered there. The caller matches the converted text exactly and still checks uniqueness.
     */
    public static function crlfVariant(string $content, string $find): ?string
    {
        if (!str_contains($find, "\n") || str_contains($find, "\r") || !str_contains($content, "\r\n")) {
            return null;
        }

        return substr_count($content, "\n") === substr_count($content, "\r\n") ? str_replace("\n", "\r\n", $find) : null;
    }

    /** @param list<int> $offsets */
    public static function ambiguous(string $path, string $content, string $find, array $offsets): string
    {
        $lines = array_map(static fn (int $offset): int => self::line($content, $offset), array_slice($offsets, 0, self::MAX_LISTED));

        return sprintf(
            '`find` occurs %d times in %s (lines %s%s). Add surrounding lines to make it unique, set "occurrence" (1-based) to pick one, or set "all": true to replace every match.',
            count($offsets),
            $path,
            implode(', ', $lines),
            count($offsets) > self::MAX_LISTED ? ', …' : '',
        );
    }

    public static function missing(string $path, string $content, string $find): string
    {
        $base = '`find` does not occur in ' . $path;
        if (str_contains($find, "\n") && !str_contains($find, "\r") && str_contains($content, "\r\n") && substr_count($content, "\n") !== substr_count($content, "\r\n")) {
            $base .= ' (the file mixes LF and CRLF line endings, so a multi-line `find` is not converted: match a single line, or write the line breaks exactly as the file has them)';
        }

        $pattern = self::whitespaceInsensitivePattern($find);
        if ($pattern !== null && preg_match($pattern, $content, $match, PREG_OFFSET_CAPTURE) === 1) {
            return sprintf(
                '%s as written, but the same text with different whitespace (indentation, tabs vs spaces or line endings) is at line %d: `%s`. Copy the exact bytes from the file.',
                $base,
                self::line($content, (int) $match[0][1]),
                self::excerpt((string) $match[0][0]),
            );
        }

        $closest = self::closestLine($content, $find);
        if ($closest !== null) {
            return sprintf('%s. The closest line is %d: `%s`. Re-read the file and copy the exact text.', $base, $closest[0], self::excerpt($closest[1]));
        }

        return $base . ' (' . (substr_count($content, "\n") + 1) . ' lines). Re-read the file; it may have changed since you read it.';
    }

    private static function whitespaceInsensitivePattern(string $find): ?string
    {
        $tokens = preg_split('/\s+/', trim($find), -1, PREG_SPLIT_NO_EMPTY);
        if ($tokens === false || $tokens === []) {
            return null;
        }

        return '/' . implode('\s+', array_map(static fn (string $token): string => preg_quote($token, '/'), $tokens)) . '/';
    }

    /** @return array{int, string}|null */
    private static function closestLine(string $content, string $find): ?array
    {
        $needle = '';
        foreach (preg_split('/\R/', $find) ?: [] as $candidate) {
            if (trim($candidate) !== '') {
                $needle = trim($candidate);
                break;
            }
        }
        if (strlen($needle) < 6) {
            return null;
        }

        $best = null;
        $bestScore = 0.0;
        foreach (preg_split('/\R/', $content) ?: [] as $index => $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }
            similar_text($needle, $trimmed, $percent);
            if ($percent > $bestScore) {
                $bestScore = $percent;
                $best = [$index + 1, $trimmed];
            }
        }

        return $best !== null && $bestScore >= 60.0 ? $best : null;
    }

    private static function excerpt(string $text): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);

        return mb_strlen($text) > self::EXCERPT ? mb_substr($text, 0, self::EXCERPT - 1) . '…' : $text;
    }
}
