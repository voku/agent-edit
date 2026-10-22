<?php

declare(strict_types=1);

namespace voku\AgentEdit\Preview;

use RuntimeException;

/**
 * What a validated plan would do, derived only from the applier's own preflight result: the staged contents, the final
 * paths and the deletions it already computed. It reads the current sources but never writes, and it adds no edit the
 * plan did not carry.
 */
final class ChangePreview
{
    /**
     * @param array{files: array<string, string>, final_paths: array<string, string>, source_hashes: array<string, string>, deletion_count?: int} $prepared
     * @return array{changes: list<array{path: string, change: string, final_path?: string, lines_added: int, lines_removed: int}>, diff: string}
     */
    public static function fromPreflight(array $prepared, string $root): array
    {
        $base = realpath($root);
        $base = rtrim(str_replace('\\', '/', $base === false ? $root : $base), '/');

        $changes = [];
        $diff = '';
        $paths = array_keys($prepared['files']);
        if (($prepared['deletion_count'] ?? 0) > 0) {
            foreach (array_keys($prepared['source_hashes']) as $deleted) {
                if (!isset($prepared['files'][$deleted])) {
                    $paths[] = $deleted;
                }
            }
        }
        sort($paths, SORT_STRING);

        foreach ($paths as $source) {
            // The direct-edit and scaffold preflights list a file to create under `files` without a source hash.
            $creation = !isset($prepared['source_hashes'][$source]) && !is_file($source) && isset($prepared['files'][$source]);
            $original = $creation ? '' : self::read($source);
            $deleted = !isset($prepared['files'][$source]);
            $content = $deleted ? '' : $prepared['files'][$source];
            $final = $deleted ? $source : ($prepared['final_paths'][$source] ?? $source);
            $moved = $final !== $source;
            $oldLabel = self::relative($base, $source);
            $newLabel = $deleted ? '/dev/null' : self::relative($base, $final);
            $text = UnifiedDiff::between($original, $content, $creation ? '/dev/null' : 'a/' . $oldLabel, $deleted ? $newLabel : 'b/' . $newLabel);

            $change = $creation ? 'created' : ($deleted ? 'deleted' : ($moved ? ($text['diff'] === '' ? 'moved' : 'moved_and_modified') : 'modified'));
            $entry = ['path' => $oldLabel, 'change' => $change];
            if ($moved) {
                $entry['final_path'] = $newLabel;
            }
            $entry['lines_added'] = $text['added'];
            $entry['lines_removed'] = $text['removed'];
            $changes[] = $entry;
            $diff .= $moved && $text['diff'] === ''
                ? 'rename from ' . $oldLabel . "\nrename to " . $newLabel . "\n"
                : $text['diff'];
        }

        return ['changes' => $changes, 'diff' => $diff];
    }

    private static function relative(string $base, string $path): string
    {
        $path = str_replace('\\', '/', $path);

        return str_starts_with($path, $base . '/') ? substr($path, strlen($base) + 1) : $path;
    }

    private static function read(string $path): string
    {
        $content = @file_get_contents($path);
        if (!is_string($content)) {
            throw new RuntimeException('Unable to read source for the change preview: ' . $path);
        }

        return $content;
    }
}
