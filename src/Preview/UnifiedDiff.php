<?php

declare(strict_types=1);

namespace voku\AgentEdit\Preview;

/**
 * Minimal deterministic line diff in unified format. It exists so a dry run can show what an apply would change without a
 * dependency: the edits are byte-range replacements, so almost every line is common and only the changed middle is aligned.
 */
final class UnifiedDiff
{
    /** Above this many compared line pairs the changed region is reported as one replaced block instead of aligned. */
    private const MAX_ALIGNMENT_CELLS = 4_000_000;

    /** @return array{added: int, removed: int, diff: string} */
    public static function between(string $old, string $new, string $oldLabel, string $newLabel, int $context = 3): array
    {
        if ($old === $new) {
            return ['added' => 0, 'removed' => 0, 'diff' => ''];
        }

        $before = self::lines($old);
        $after = self::lines($new);
        $operations = self::operations($before, $after);

        $added = 0;
        $removed = 0;
        foreach ($operations as [$kind]) {
            $added += $kind === '+' ? 1 : 0;
            $removed += $kind === '-' ? 1 : 0;
        }

        $out = '--- ' . $oldLabel . "\n+++ " . $newLabel . "\n";
        foreach (self::hunks($operations, $context) as $hunk) {
            $out .= self::renderHunk($hunk, $before, $after);
        }

        return ['added' => $added, 'removed' => $removed, 'diff' => $out];
    }

    /**
     * Lines of the text; a line that ends in a newline keeps it, so the last line of a file without a final newline
     * compares unequal to the same text with one and the difference is reported like `diff -u` does.
     *
     * @return list<string>
     */
    private static function lines(string $text): array
    {
        if ($text === '') {
            return [];
        }
        $lines = explode("\n", $text);
        $last = array_pop($lines);
        $lines = array_map(static fn (string $line): string => $line . "\n", $lines);
        if ($last !== '') {
            $lines[] = $last;
        }

        return $lines;
    }

    /**
     * @param list<string> $before
     * @param list<string> $after
     * @return list<array{string, int, int}> kind (' ', '-', '+'), index into $before, index into $after
     */
    private static function operations(array $before, array $after): array
    {
        $beforeCount = count($before);
        $afterCount = count($after);
        $prefix = 0;
        while ($prefix < $beforeCount && $prefix < $afterCount && $before[$prefix] === $after[$prefix]) {
            ++$prefix;
        }
        $suffix = 0;
        while ($suffix < $beforeCount - $prefix && $suffix < $afterCount - $prefix && $before[$beforeCount - 1 - $suffix] === $after[$afterCount - 1 - $suffix]) {
            ++$suffix;
        }

        $operations = [];
        for ($index = 0; $index < $prefix; ++$index) {
            $operations[] = [' ', $index, $index];
        }

        $beforeMiddle = array_slice($before, $prefix, $beforeCount - $prefix - $suffix);
        $afterMiddle = array_slice($after, $prefix, $afterCount - $prefix - $suffix);
        foreach (self::alignMiddle($beforeMiddle, $afterMiddle) as [$kind, $left, $right]) {
            $operations[] = [$kind, $prefix + $left, $prefix + $right];
        }

        for ($index = $suffix; $index > 0; --$index) {
            $operations[] = [' ', $beforeCount - $index, $afterCount - $index];
        }

        return $operations;
    }

    /**
     * @param list<string> $before
     * @param list<string> $after
     * @return list<array{string, int, int}>
     */
    private static function alignMiddle(array $before, array $after): array
    {
        $rows = count($before);
        $columns = count($after);
        if ($rows === 0 || $columns === 0 || $rows * $columns > self::MAX_ALIGNMENT_CELLS) {
            $operations = [];
            foreach (array_keys($before) as $index) {
                $operations[] = ['-', $index, 0];
            }
            foreach (array_keys($after) as $index) {
                $operations[] = ['+', $rows, $index];
            }

            return $operations;
        }

        // Longest common subsequence lengths over suffixes, then a forward walk emits the script.
        $lengths = array_fill(0, $rows + 1, array_fill(0, $columns + 1, 0));
        for ($row = $rows - 1; $row >= 0; --$row) {
            for ($column = $columns - 1; $column >= 0; --$column) {
                $lengths[$row][$column] = $before[$row] === $after[$column]
                    ? $lengths[$row + 1][$column + 1] + 1
                    : max($lengths[$row + 1][$column], $lengths[$row][$column + 1]);
            }
        }

        $operations = [];
        $row = 0;
        $column = 0;
        while ($row < $rows && $column < $columns) {
            if ($before[$row] === $after[$column]) {
                $operations[] = [' ', $row, $column];
                ++$row;
                ++$column;
            } elseif ($lengths[$row + 1][$column] >= $lengths[$row][$column + 1]) {
                $operations[] = ['-', $row, $column];
                ++$row;
            } else {
                $operations[] = ['+', $row, $column];
                ++$column;
            }
        }
        for (; $row < $rows; ++$row) {
            $operations[] = ['-', $row, $column];
        }
        for (; $column < $columns; ++$column) {
            $operations[] = ['+', $row, $column];
        }

        return $operations;
    }

    /**
     * @param list<array{string, int, int}> $operations
     * @return list<list<array{string, int, int}>>
     */
    private static function hunks(array $operations, int $context): array
    {
        $changed = [];
        foreach ($operations as $position => [$kind]) {
            if ($kind !== ' ') {
                $changed[] = $position;
            }
        }

        $hunks = [];
        $start = null;
        $end = null;
        foreach ($changed as $position) {
            $from = max(0, $position - $context);
            $to = min(count($operations) - 1, $position + $context);
            if ($start === null || $from > $end + 1) {
                if ($start !== null) {
                    $hunks[] = array_slice($operations, $start, $end - $start + 1);
                }
                $start = $from;
            }
            $end = $to;
        }
        if ($start !== null) {
            $hunks[] = array_slice($operations, $start, $end - $start + 1);
        }

        return $hunks;
    }

    /**
     * @param list<array{string, int, int}> $hunk
     * @param list<string> $before
     * @param list<string> $after
     */
    private static function renderHunk(array $hunk, array $before, array $after): string
    {
        $oldStart = null;
        $newStart = null;
        $oldLength = 0;
        $newLength = 0;
        $body = '';
        foreach ($hunk as [$kind, $left, $right]) {
            if ($kind !== '+') {
                $oldStart ??= $left + 1;
                ++$oldLength;
            }
            if ($kind !== '-') {
                $newStart ??= $right + 1;
                ++$newLength;
            }
            $text = $kind === '+' ? $after[$right] : $before[$left];
            $body .= $kind . $text;
            if (!str_ends_with($text, "\n")) {
                $body .= "\n\\ No newline at end of file\n";
            }
        }

        // A hunk that only adds (or only removes) lines anchors at the line before it, as `diff -u` does.
        $oldStart ??= ($hunk[0][1] ?? 0);
        $newStart ??= ($hunk[0][2] ?? 0);

        return '@@ -' . self::range($oldStart, $oldLength) . ' +' . self::range($newStart, $newLength) . " @@\n" . $body;
    }

    private static function range(int $start, int $length): string
    {
        return $length === 1 ? (string) $start : $start . ',' . $length;
    }
}
