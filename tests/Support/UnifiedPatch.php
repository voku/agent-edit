<?php

declare(strict_types=1);

namespace voku\AgentEdit\Tests\Support;

use RuntimeException;

/**
 * Independent oracle for the unified diffs agent-edit prints: applies a diff to the old text and returns the new text.
 * It is strict (every context and removed line must match) so a diff that does not describe the change cannot pass.
 */
final class UnifiedPatch
{
    public static function apply(string $old, string $diff): string
    {
        $oldLines = self::lines($old);
        $result = [];
        $position = 0;

        $diffLines = $diff === '' ? [] : (preg_split('/(?<=\n)/', $diff, -1, PREG_SPLIT_NO_EMPTY) ?: []);
        $index = 0;
        $count = count($diffLines);
        while ($index < $count && !str_starts_with($diffLines[$index], '@@ ')) {
            ++$index;
        }
        while ($index < $count) {
            if (preg_match('/^@@ -(\d+)(?:,(\d+))? \+(\d+)(?:,(\d+))? @@\n$/', $diffLines[$index], $header) !== 1) {
                throw new RuntimeException('Malformed hunk header: ' . $diffLines[$index]);
            }
            $oldStart = (int) $header[1];
            $oldLength = $header[2] !== '' ? (int) $header[2] : 1;
            $newLength = ($header[4] ?? '') !== '' ? (int) $header[4] : 1;
            $target = $oldLength === 0 ? $oldStart : $oldStart - 1;
            while ($position < $target) {
                $result[] = $oldLines[$position++] ?? throw new RuntimeException('Hunk starts past the end of the old text.');
            }

            ++$index;
            $consumed = 0;
            $produced = 0;
            while ($index < $count && !str_starts_with($diffLines[$index], '@@ ') && !str_starts_with($diffLines[$index], '--- ')) {
                $line = $diffLines[$index];
                $kind = $line[0];
                $text = substr($line, 1);
                if (($diffLines[$index + 1] ?? '') === "\\ No newline at end of file\n") {
                    $text = rtrim($text, "\n");
                    ++$index;
                }
                if ($kind === ' ' || $kind === '-') {
                    if (($oldLines[$position] ?? null) !== $text) {
                        throw new RuntimeException('Hunk does not match the old text at line ' . ($position + 1) . '.');
                    }
                    ++$position;
                    ++$consumed;
                }
                if ($kind === ' ' || $kind === '+') {
                    $result[] = $text;
                    ++$produced;
                }
                if (!in_array($kind, [' ', '-', '+'], true)) {
                    throw new RuntimeException('Unexpected diff line: ' . $line);
                }
                ++$index;
            }
            if ($consumed !== $oldLength || $produced !== $newLength) {
                throw new RuntimeException(sprintf('Hunk header says -%d +%d but the body has -%d +%d.', $oldLength, $newLength, $consumed, $produced));
            }
        }
        while ($position < count($oldLines)) {
            $result[] = $oldLines[$position++];
        }

        return implode('', $result);
    }

    /** @return list<string> lines with their terminating newline, the last one possibly without */
    private static function lines(string $text): array
    {
        return $text === '' ? [] : (preg_split('/(?<=\n)/', $text, -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }
}
