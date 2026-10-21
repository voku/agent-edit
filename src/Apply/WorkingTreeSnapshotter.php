<?php

declare(strict_types=1);

namespace voku\AgentEdit\Apply;

/**
 * Takes a before/after picture of the working tree so `changed_files` is observed, not claimed.
 *
 * Asking the runner what it changed would make the answer sheet self-reported at exactly the point
 * where a verifier needs an independent fact. Git already knows, so this asks Git.
 *
 * A repository without Git, or a Git that fails for any reason, yields an unavailable snapshot: an
 * edit must not fail because its bookkeeping could not run.
 */
final readonly class WorkingTreeSnapshotter
{
    public function capture(string $repositoryRoot): WorkingTreeSnapshot
    {
        $root = realpath($repositoryRoot);
        if ($root === false) {
            return WorkingTreeSnapshot::unavailable();
        }

        // Let Git decide whether this path is a working tree. A linked `git worktree` stores `.git`
        // as a file pointing at the main repository metadata, so checking for a `.git` directory
        // incorrectly downgraded real worktrees to unavailable evidence.
        $head = $this->run($root, ['git', 'rev-parse', 'HEAD']);
        $status = $this->run($root, ['git', 'status', '--porcelain=v1', '-z', '--untracked-files=all']);
        $topLevel = $this->run($root, ['git', 'rev-parse', '--show-toplevel']);
        $prefix = $this->run($root, ['git', 'rev-parse', '--show-prefix']);
        if ($status === null || $topLevel === null || $prefix === null) {
            return WorkingTreeSnapshot::unavailable();
        }

        // Porcelain paths are always relative to the Git top level, which differs from the repository root when the
        // project is a subdirectory of a larger work tree. Hash from the top level and report root-relative paths.
        // Git appends one newline. Spaces (and even newlines) can be part of the actual directory name.
        $base = rtrim($this->withoutCommandLineEnding($topLevel), '/');
        $prefix = $this->withoutCommandLineEnding($prefix);

        $entries = [];
        foreach ($this->parseStatus($status) as $path => $code) {
            if ($prefix !== '' && !str_starts_with($path, $prefix)) {
                continue;
            }
            $relative = substr($path, strlen($prefix));
            if ($relative === '') {
                continue;
            }
            $absolute = $base . '/' . $path;
            $hash = is_file($absolute) ? hash_file('sha256', $absolute) : false;
            $entries[$relative] = $code . ':' . (is_string($hash) ? $hash : 'absent');
        }
        ksort($entries, SORT_STRING);

        return new WorkingTreeSnapshot(true, $head === null ? null : trim($head), $entries);
    }

    /**
     * Which of the root-relative paths Git ignores. `git status` never reports ignored files, so an edit to one is
     * invisible to the before/after observation and has to be accounted for separately. Tracked files are never
     * ignored in this sense, and a missing repository or a failing Git yields no ignored paths.
     *
     * @param list<string> $relativePaths
     * @return list<string>
     */
    public function ignoredPaths(string $repositoryRoot, array $relativePaths): array
    {
        $root = realpath($repositoryRoot);
        if ($root === false || $relativePaths === []) {
            return [];
        }
        $output = $this->run($root, ['git', 'check-ignore', '-z', '--stdin'], implode("\0", $relativePaths) . "\0");
        if ($output === null) {
            return [];
        }

        $ignored = array_values(array_filter(explode("\0", $output), static fn (string $path): bool => $path !== ''));
        $ignored = array_values(array_intersect($relativePaths, $ignored));
        sort($ignored, SORT_STRING);

        return $ignored;
    }

    /**
     * `-z` output is NUL-separated with no trailing newline, and a rename entry carries its origin
     * path as a second record - which must be consumed, not read as a status line of its own.
     *
     * @return array<string, string>
     */
    private function parseStatus(string $status): array
    {
        $records = explode("\0", $status);
        $entries = [];
        for ($i = 0, $count = count($records); $i < $count; ++$i) {
            $record = $records[$i];
            if (strlen($record) < 4) {
                continue;
            }

            $code = substr($record, 0, 2);
            $path = substr($record, 3);
            if (str_contains($code, 'R') || str_contains($code, 'C')) {
                $origin = $records[$i + 1] ?? null;
                ++$i;
                if (is_string($origin) && $origin !== '') {
                    $entries[$origin] = $code . '<-';
                }
            }

            $entries[$path] = $code;
        }

        return $entries;
    }

    /** Removes exactly the line ending added by line-oriented Git commands without trimming valid path bytes. */
    private function withoutCommandLineEnding(string $output): string
    {
        if (str_ends_with($output, "\r\n")) {
            return substr($output, 0, -2);
        }
        if (str_ends_with($output, "\n")) {
            return substr($output, 0, -1);
        }

        return $output;
    }

    /** @param non-empty-list<string> $command */
    private function run(string $workingDirectory, array $command, ?string $stdin = null): ?string
    {
        $process = proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $workingDirectory,
        );
        if (!is_resource($process)) {
            return null;
        }
        if ($stdin !== null) {
            fwrite($pipes[0], $stdin);
        }
        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]);
        // Drained as well: an undrained stderr pipe can block the child once its buffer fills.
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return proc_close($process) === 0 && is_string($stdout) ? $stdout : null;
    }
}
