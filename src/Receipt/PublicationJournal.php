<?php

declare(strict_types=1);

namespace voku\AgentEdit\Receipt;

use JsonException;
use RuntimeException;
use voku\AgentEdit\Apply\WorkingTreeSnapshot;
use voku\AgentEdit\Verify\MapManifestEvidence;

/**
 * Write-ahead record of one source publication, kept in the receipt bundle while source and evidence can disagree.
 *
 * It is written under the project mutation lock after the plan was proven and before the first source byte changes,
 * and removed only once the receipt is persisted and every touched path is observed in one consistent state (all
 * before, or all after). A journal that survives therefore means "publication or its evidence did not finish": a
 * receipt write failed, a rollback was incomplete, or the process was killed. `EditEngine::recover()` resolves it.
 *
 * For every touched path it records the digest before and after publication (null = file absent) and, for an
 * existing file, a pre-image under `before/` so an interrupted publication can be rolled back from the bundle alone.
 * A move is two entries (source: present -> absent, destination: absent -> present).
 *
 * Durability: the journal and pre-images are flushed (`fsync`) before they are renamed into place. That makes them
 * survive a killed process. Whether a rename survives power loss depends on the filesystem; that is not promised.
 */
final class PublicationJournal
{
    public const FILE = 'publication-journal.json';
    public const SCHEMA = 'agent-edit-publication-journal@1';
    public const PRE_IMAGE_DIR = 'before';

    /** Temporary files the publishers create next to a target (`<path>.agent-edit-{tx|plan}-{stage|backup}-<hex>`). */
    private const TEMPORARY_PATTERN = '/^\.agent-edit-(?:tx|plan)-(?:stage|backup)-[0-9a-f]{16}$/';

    public const STATE_BEFORE = 'before';
    public const STATE_AFTER = 'after';
    public const STATE_DISPLACED = 'displaced';
    public const STATE_UNEXPECTED = 'unexpected';

    /**
     * Entries from an applier preflight (`files`, `final_paths`, `source_hashes`; absolute paths), with the current
     * bytes of every existing file read now. Paths are made relative to `$root`.
     *
     * @param array{files: array<string, string>, final_paths: array<string, string>, source_hashes: array<string, string>} $prepared
     * @return list<array{path: string, before_sha256: string|null, after_sha256: string|null, before: string|null}>
     */
    public static function entriesFromPrepared(array $prepared, string $root): array
    {
        $root = self::realRoot($root);
        $entries = [];
        $sources = array_unique([...array_keys($prepared['source_hashes']), ...array_keys($prepared['files'])]);
        sort($sources, SORT_STRING);
        foreach ($sources as $source) {
            $source = str_replace('\\', '/', $source);
            $expected = $prepared['source_hashes'][$source] ?? null;
            $before = null;
            if ($expected !== null) {
                $before = @file_get_contents($source);
                if (!is_string($before) || !hash_equals(self::hex($expected), hash('sha256', $before))) {
                    throw new RuntimeException('Source changed before publication could be journaled: ' . self::relative($root, $source));
                }
            }
            $content = $prepared['files'][$source] ?? null;
            $final = str_replace('\\', '/', $prepared['final_paths'][$source] ?? $source);
            if ($content !== null && $final !== $source) {
                $entries[] = self::entry($root, $source, $before, null);
                $entries[] = self::entry($root, $final, null, hash('sha256', $content));
                continue;
            }
            $entries[] = self::entry($root, $source, $before, $content === null ? null : hash('sha256', $content));
        }
        usort($entries, static fn (array $a, array $b): int => strcmp($a['path'], $b['path']));

        return $entries;
    }

    /**
     * Persists the pre-images and then the journal. Nothing has been published yet when this throws.
     *
     * @param list<array{path: string, before_sha256: string|null, after_sha256: string|null, before: string|null}> $entries
     * @param array<string, mixed> $context receipt template, observation and direct-edit details, stored as given
     */
    public static function begin(string $bundle, array $entries, array $context): void
    {
        $recorded = [];
        foreach ($entries as $entry) {
            $preImage = null;
            if ($entry['before'] !== null) {
                $preImage = self::PRE_IMAGE_DIR . '/' . $entry['path'];
                self::durableWrite($bundle . '/' . $preImage, $entry['before']);
            }
            $recorded[] = [
                'path' => $entry['path'],
                'before_sha256' => $entry['before_sha256'],
                'after_sha256' => $entry['after_sha256'],
                'pre_image' => $preImage,
            ];
        }
        self::durableWrite($bundle . '/' . self::FILE, self::encode([
            'schema' => self::SCHEMA,
            'entries' => $recorded,
        ] + $context));
    }

    /**
     * Journals one publication described by an applier preflight, together with everything a recovered receipt needs:
     * the receipt template, the pre-publication observation (git snapshot, or the Map manifest when git is not
     * available) and, for direct edits, the per-file edit evidence.
     *
     * @param array<string, mixed> $receiptTemplate
     * @param array{files: array<string, string>, final_paths: array<string, string>, source_hashes: array<string, string>} $prepared
     * @param array<string, string>|null $manifest
     * @param array<string, mixed>|null $direct
     */
    public static function start(ApplyRequest $request, array $receiptTemplate, array $prepared, WorkingTreeSnapshot $before, ?array $manifest, ?array $direct): void
    {
        $mapRoot = $manifest === null ? false : realpath($request->mapRoot);
        if ($manifest !== null) {
            self::durableWrite($request->outputDirectory . '/' . MapManifestEvidence::FILE, MapManifestEvidence::encode($manifest));
        }
        self::begin($request->outputDirectory, self::entriesFromPrepared($prepared, $request->repositoryRoot), [
            'receipt' => $receiptTemplate,
            'observation' => [
                'git' => $before->available ? ['head' => $before->head, 'entries' => (object) $before->entries] : null,
                'map_manifest' => $manifest === null ? null : (object) $manifest,
                'map_root' => is_string($mapRoot) ? $mapRoot : null,
            ],
            'direct_edit' => $direct,
        ]);
    }

    public static function exists(string $bundle): bool
    {
        return is_file($bundle . '/' . self::FILE);
    }

    /** @return array<string, mixed>|null the journal, or null when the bundle has none */
    public static function read(string $bundle): ?array
    {
        $path = $bundle . '/' . self::FILE;
        if (!is_file($path)) {
            return null;
        }
        $raw = file_get_contents($path);
        try {
            $journal = is_string($raw) ? json_decode($raw, true, 512, JSON_THROW_ON_ERROR) : null;
        } catch (JsonException) {
            $journal = null;
        }
        if (!is_array($journal) || ($journal['schema'] ?? null) !== self::SCHEMA || !is_array($journal['entries'] ?? null)) {
            throw new RuntimeException('Publication journal is unreadable or of an unknown schema: ' . $path);
        }

        return $journal;
    }

    public static function remove(string $bundle): void
    {
        $path = $bundle . '/' . self::FILE;
        if (is_file($path) && !@unlink($path)) {
            throw new RuntimeException('Unable to remove the publication journal: ' . $path);
        }
    }

    /**
     * The observed state of every journaled path, and the overall verdict:
     * `published` (all after), `not_published` (all before), `interrupted` (a mix, restorable from the bundle), or
     * `conflict` (some path matches neither digest: someone else changed it, so nothing may be restored automatically).
     *
     * A path that is missing although it existed before, while a publisher backup with its original digest sits next
     * to it, is `displaced`: publication had moved it aside and was killed before putting the new bytes in place.
     *
     * @param list<array<string, mixed>> $entries
     * @return array{verdict: string, files: list<array{path: string, state: string}>}
     */
    public static function observe(string $root, array $entries): array
    {
        $root = self::realRoot($root);
        $files = [];
        $states = [];
        foreach ($entries as $entry) {
            $relative = self::canonical((string) ($entry['path'] ?? ''));
            $absolute = $root . '/' . $relative;
            $before = self::nullableString($entry['before_sha256'] ?? null);
            $after = self::nullableString($entry['after_sha256'] ?? null);
            $current = is_file($absolute) && !is_link($absolute) ? hash_file('sha256', $absolute) : null;
            $current = $current === false ? null : $current;
            $present = file_exists($absolute) || is_link($absolute);

            $state = match (true) {
                $present && $current === null => self::STATE_UNEXPECTED,
                $current === $after => self::STATE_AFTER,
                $current === $before => self::STATE_BEFORE,
                $current === null && $before !== null && self::displacedBackup($absolute, $before) => self::STATE_DISPLACED,
                default => self::STATE_UNEXPECTED,
            };
            $files[] = ['path' => $relative, 'state' => $state];
            $states[$state] = true;
        }

        $verdict = match (true) {
            isset($states[self::STATE_UNEXPECTED]) => 'conflict',
            array_keys($states) === [self::STATE_AFTER] => 'published',
            array_keys($states) === [self::STATE_BEFORE] => 'not_published',
            default => 'interrupted',
        };

        return ['verdict' => $verdict, 'files' => $files];
    }

    /**
     * Publisher temporary files (stage copies, backups) left next to journaled paths by an interrupted publication.
     *
     * @param list<array<string, mixed>> $entries
     * @return list<string> repository-relative paths
     */
    public static function strayTemporaryFiles(string $root, array $entries): array
    {
        $root = self::realRoot($root);
        $found = [];
        foreach ($entries as $entry) {
            $absolute = $root . '/' . self::canonical((string) ($entry['path'] ?? ''));
            foreach (self::temporarySiblings($absolute) as $temporary) {
                $found[self::relative($root, $temporary)] = true;
            }
        }
        $paths = array_keys($found);
        sort($paths, SORT_STRING);

        return $paths;
    }

    public static function canonical(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        if ($path === '' || str_starts_with($path, '/') || str_contains($path, "\0")) {
            throw new RuntimeException('Publication journal contains a non-relative path: ' . $path);
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new RuntimeException('Publication journal contains a non-canonical path: ' . $path);
            }
        }

        return $path;
    }

    /** Writes `$content` to a same-directory temporary file, flushes it to disk and renames it into place. */
    public static function durableWrite(string $path, string $content): void
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create evidence directory: ' . $directory);
        }
        $temporary = $path . '.tmp-' . getmypid() . '-' . bin2hex(random_bytes(4));
        $handle = @fopen($temporary, 'xb');
        if ($handle === false) {
            throw new RuntimeException('Unable to write evidence: ' . $temporary);
        }
        $written = fwrite($handle, $content);
        $flushed = fflush($handle) && fsync($handle);
        fclose($handle);
        if ($written !== strlen($content) || !$flushed || !@rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('Unable to persist evidence: ' . $path);
        }
    }

    /** @param array<string, mixed> $payload */
    public static function encode(array $payload): string
    {
        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    }

    /** @return array{path: string, before_sha256: string|null, after_sha256: string|null, before: string|null} */
    private static function entry(string $root, string $absolute, ?string $before, ?string $afterSha256): array
    {
        return [
            'path' => self::relative($root, $absolute),
            'before_sha256' => $before === null ? null : hash('sha256', $before),
            'after_sha256' => $afterSha256,
            'before' => $before,
        ];
    }

    private static function displacedBackup(string $absolute, string $beforeSha256): bool
    {
        foreach (self::temporarySiblings($absolute) as $temporary) {
            if (str_contains(basename($temporary), '-backup-') && hash_file('sha256', $temporary) === $beforeSha256) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private static function temporarySiblings(string $absolute): array
    {
        $directory = dirname($absolute);
        $prefix = basename($absolute);
        $siblings = is_dir($directory) ? scandir($directory) : false;
        $found = [];
        foreach ($siblings === false ? [] : $siblings as $name) {
            // Exactly `<basename><suffix>`: `A.php.agent-edit-...` belongs to `A.php`, never to `A.php.bak`.
            if (str_starts_with($name, $prefix)
                && preg_match(self::TEMPORARY_PATTERN, substr($name, strlen($prefix))) === 1
                && is_file($directory . '/' . $name) && !is_link($directory . '/' . $name)) {
                $found[] = $directory . '/' . $name;
            }
        }
        sort($found, SORT_STRING);

        return $found;
    }

    private static function realRoot(string $root): string
    {
        $real = realpath($root);
        if (!is_string($real)) {
            throw new RuntimeException('Unable to resolve the project root: ' . $root);
        }

        return rtrim(str_replace('\\', '/', $real), '/');
    }

    private static function relative(string $root, string $absolute): string
    {
        $absolute = str_replace('\\', '/', $absolute);
        if (!str_starts_with($absolute, $root . '/')) {
            // A Map root inside a symlinked directory: compare against the resolved parent instead.
            $parent = realpath(dirname($absolute));
            $absolute = is_string($parent) ? str_replace('\\', '/', $parent) . '/' . basename($absolute) : $absolute;
        }
        if (!str_starts_with($absolute, $root . '/')) {
            throw new RuntimeException('Publication path is outside the project root: ' . $absolute);
        }

        return self::canonical(substr($absolute, strlen($root) + 1));
    }

    private static function hex(string $sha256): string
    {
        return str_starts_with($sha256, 'sha256:') ? substr($sha256, 7) : $sha256;
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
