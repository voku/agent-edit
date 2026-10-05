<?php

declare(strict_types=1);

namespace voku\AgentEdit\Verify;

use JsonException;
use RuntimeException;
use voku\AgentMap\Index\AgentMapIndex;

/**
 * Git-free observation of changed files, limited to what the Map indexes.
 *
 * Before a mutation the receipt bundle stores `map-scope-before.json` (path => content hash for every
 * Map-indexed file) and the receipt references it by path, hash and source only. A verifier recomputes the
 * same manifest from disk and the refreshed Map and diffs the two.
 *
 * That proves exactly one thing: which Map-indexed files changed. A file outside the Map index can change
 * without leaving a trace here, so a verdict built on this evidence is never `passed`.
 */
final readonly class MapManifestEvidence
{
    public const SOURCE = 'map_manifest_diff';
    public const FILE = 'map-scope-before.json';
    private const ABSENT = 'absent';

    /**
     * @param list<string> $additionalPaths validated project-relative publication paths
     * @return array<string, string> project-relative path => `sha256:<hex>|absent`, sorted by path
     */
    public static function capture(AgentMapIndex $map, string $mapRoot, array $additionalPaths = []): array
    {
        $manifest = [];
        foreach ($map->files as $file) {
            $hash = self::hashOf($mapRoot, $file->path);
            if ($hash !== self::ABSENT) {
                $manifest[$file->path] = $hash;
            }
        }
        // New move destinations are absent from the old Map. Observe their absence before publishing so
        // changedSince() can report both sides of the move without trusting the runner's claimed changes.
        foreach ($additionalPaths as $path) {
            $manifest[$path] = self::hashOf($mapRoot, $path);
        }
        ksort($manifest, SORT_STRING);

        return $manifest;
    }

    /** @param array<string, string> $manifest */
    public static function encode(array $manifest): string
    {
        return json_encode(
            ['schema_version' => '1.0', 'kind' => 'map_scope_before', 'files' => $manifest],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ) . "\n";
    }

    /**
     * Manifest files whose content differs from disk now; files the manifest never listed are not visible here.
     *
     * @param array<string, string> $before
     * @return list<string>
     */
    public static function changedSince(array $before, string $mapRoot): array
    {
        $changed = [];
        foreach ($before as $path => $hash) {
            if (self::hashOf($mapRoot, $path) !== $hash) {
                $changed[] = $path;
            }
        }
        sort($changed, SORT_STRING);

        return $changed;
    }

    /**
     * Receipt fields that reference the stored manifest; they carry no file list of their own.
     *
     * @return array{source: string, path: string, sha256: string}
     */
    public static function reference(string $encoded): array
    {
        return [
            'source' => 'map_manifest',
            'path' => self::FILE,
            'sha256' => 'sha256:' . hash('sha256', $encoded),
        ];
    }

    /**
     * Map-indexed files whose content differs between the stored manifest and the current disk state.
     *
     * @param array<string, mixed> $execution decoded receipt
     * @return list<string>
     */
    public function observe(array $execution, string $bundle, AgentMapIndex $current, string $mapRoot): array
    {
        $reference = $execution['scope_evidence'] ?? null;
        if (!is_array($reference)
            || ($reference['source'] ?? null) !== 'map_manifest'
            || ($reference['path'] ?? null) !== self::FILE
            || !is_string($reference['sha256'] ?? null)
        ) {
            throw new RuntimeException('Map manifest verification requires a bound map-scope-before.json reference.');
        }

        $raw = @file_get_contents($bundle . '/' . self::FILE);
        if (!is_string($raw) || !hash_equals($reference['sha256'], 'sha256:' . hash('sha256', $raw))) {
            throw new RuntimeException('Bound Map scope manifest is missing or changed after execution.');
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Malformed Map scope manifest: ' . $exception->getMessage(), 0, $exception);
        }
        $before = is_array($decoded) ? ($decoded['files'] ?? null) : null;
        if (!is_array($before)) {
            throw new RuntimeException('Map scope manifest has no files object.');
        }

        $paths = [];
        foreach ($before as $path => $hash) {
            if (!is_string($path) || !is_string($hash)) {
                throw new RuntimeException('Map scope manifest contains an invalid entry.');
            }
            $paths[$path] = true;
        }
        foreach ($current->files as $file) {
            $paths[$file->path] = true;
        }

        $changed = [];
        foreach (array_keys($paths) as $path) {
            if (($before[$path] ?? self::ABSENT) !== self::hashOf($mapRoot, (string) $path)) {
                $changed[] = (string) $path;
            }
        }
        sort($changed, SORT_STRING);

        return $changed;
    }

    /**
     * What a verdict built on this evidence does and does not establish.
     *
     * @return array{status: string, changed_files_source: string, proven: string, unproven: string}
     */
    public static function scope(): array
    {
        return [
            'status' => 'scope_unproven',
            'changed_files_source' => self::SOURCE,
            'proven' => 'map_indexed_files',
            'unproven' => 'files_outside_map_index',
        ];
    }

    private static function hashOf(string $root, string $path): string
    {
        $absolute = rtrim($root, '/') . '/' . $path;
        $hash = is_file($absolute) ? hash_file('sha256', $absolute) : false;

        return is_string($hash) ? 'sha256:' . $hash : self::ABSENT;
    }
}
