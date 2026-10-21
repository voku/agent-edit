<?php

declare(strict_types=1);

namespace voku\AgentEdit\Apply;

use Closure;
use RuntimeException;
use Throwable;

/**
 * All-or-nothing publication of exact file contents: preconditions are proven, every new content is staged next to its
 * target and PHP-syntax-checked, originals are moved to backups, and any failure restores every file and removes every
 * created file and directory. It never decides *what* to write; callers hand it final bytes plus the digest each
 * existing file must still have.
 */
final readonly class FileTransaction
{
    /** @var (Closure(string, string): bool)|null */
    private ?Closure $renameOperation;

    /** @var (Closure(string): array{exit_code: int, stdout: string, stderr: string})|null */
    private ?Closure $lintOperation;

    /**
     * @param (Closure(string, string): bool)|null $renameOperation test seam for publication failures
     * @param (Closure(string): array{exit_code: int, stdout: string, stderr: string})|null $lintOperation
     */
    public function __construct(?Closure $renameOperation = null, ?Closure $lintOperation = null)
    {
        $this->renameOperation = $renameOperation;
        $this->lintOperation = $lintOperation;
    }

    /**
     * @param list<FileOperation> $operations
     * @return list<string> non-fatal cleanup warnings after a successful publication
     */
    public function run(array $operations, string $root): array
    {
        $realRoot = realpath($root);
        if (!is_string($realRoot)) {
            throw new RuntimeException('Unable to resolve the transaction root.');
        }
        $realRoot = rtrim(str_replace('\\', '/', $realRoot), '/');
        if ($operations === []) {
            throw new RuntimeException('A file transaction needs at least one operation.');
        }

        $this->assertPreconditions($operations, $realRoot);

        /** @var array<string, string> $staged operation path => staged path */
        $staged = [];
        /** @var list<string> $createdDirectories */
        $createdDirectories = [];
        /** @var array<string, string> $backups */
        $backups = [];
        /** @var array<string, true> $published */
        $published = [];

        try {
            foreach ($operations as $operation) {
                if ($operation->isCreate()) {
                    array_push($createdDirectories, ...$this->createParents($realRoot, dirname($operation->path)));
                }
            }
            foreach ($operations as $operation) {
                if ($operation->content === null) {
                    continue;
                }
                $temporary = $this->temporaryPath($operation->path, 'stage');
                $staged[$operation->path] = $temporary;
                $written = file_put_contents($temporary, $operation->content, LOCK_EX);
                if (!is_int($written) || $written !== strlen($operation->content)) {
                    throw new RuntimeException('Unable to stage complete file contents: ' . $operation->path);
                }
                $permissions = $operation->isCreate() ? 0o644 : fileperms($operation->path);
                if (!is_int($permissions) || !chmod($temporary, $permissions & 0o777)) {
                    throw new RuntimeException('Unable to set staged file permissions: ' . $operation->path);
                }
            }
            foreach ($operations as $operation) {
                if ($operation->content === null || !str_ends_with(strtolower($operation->path), '.php')) {
                    continue;
                }
                $lint = $this->lint($staged[$operation->path]);
                if ($lint['exit_code'] !== 0) {
                    $detail = trim($lint['stderr'] !== '' ? $lint['stderr'] : $lint['stdout']);
                    $detail = str_replace($staged[$operation->path], $operation->path, $detail);
                    throw new RuntimeException(
                        'Rewritten PHP failed syntax validation before publication: '
                        . $this->display($realRoot, $operation->path)
                        . ($detail === '' ? '' : ' (' . $detail . ')'),
                    );
                }
            }

            // Re-prove the preconditions after staging: nothing may have changed while the files were being prepared.
            $this->assertPreconditions($operations, $realRoot);

            foreach ($operations as $operation) {
                if ($operation->isCreate()) {
                    if (!$this->move($staged[$operation->path], $operation->path)) {
                        throw new RuntimeException('Unable to publish created file: ' . $this->display($realRoot, $operation->path));
                    }
                    $published[$operation->path] = true;
                    continue;
                }

                $backup = $this->temporaryPath($operation->path, 'backup');
                if (!$this->move($operation->path, $backup)) {
                    throw new RuntimeException('Unable to back up file before publication: ' . $this->display($realRoot, $operation->path));
                }
                $backups[$operation->path] = $backup;
                $backupHash = hash_file('sha256', $backup);
                if (!is_string($backupHash) || !hash_equals((string) $operation->expectedSha256, $backupHash)) {
                    throw new RuntimeException('File changed during publication precondition capture: ' . $this->display($realRoot, $operation->path));
                }
                if ($operation->isDelete()) {
                    continue;
                }
                if (!$this->move($staged[$operation->path], $operation->path)) {
                    throw new RuntimeException('Unable to publish staged file: ' . $this->display($realRoot, $operation->path));
                }
                $published[$operation->path] = true;
            }
        } catch (Throwable $exception) {
            $failures = [
                ...$this->rollback($operations, $backups, $published),
                ...$this->cleanupFiles(array_values($staged)),
                ...$this->cleanupDirectories($createdDirectories),
            ];
            if ($failures !== []) {
                throw new RuntimeException(
                    'File transaction failed and rollback was incomplete: ' . implode('; ', $failures),
                    0,
                    $exception,
                );
            }

            throw new RuntimeException('File transaction failed; every file was restored. ' . $exception->getMessage(), 0, $exception);
        }

        return $this->cleanupFiles(array_values($backups));
    }

    /**
     * @param list<FileOperation> $operations
     */
    private function assertPreconditions(array $operations, string $realRoot): void
    {
        $seen = [];
        foreach ($operations as $operation) {
            $path = str_replace('\\', '/', $operation->path);
            if (isset($seen[$path])) {
                throw new RuntimeException('A file transaction names the same path twice: ' . $this->display($realRoot, $path));
            }
            $seen[$path] = true;
            if (!str_starts_with($path, $realRoot . '/') || str_contains($path, '/../') || str_contains($path, "\0")) {
                throw new RuntimeException('File transaction path escapes the project root: ' . $path);
            }

            if ($operation->isCreate()) {
                if (file_exists($path) || is_link($path)) {
                    throw new RuntimeException('Refusing to create a file that already exists: ' . $this->display($realRoot, $path));
                }
                $this->assertParentInside($realRoot, $path);
                continue;
            }

            if (is_link($path) || !is_file($path)) {
                throw new RuntimeException('File to change is missing or not a regular file: ' . $this->display($realRoot, $path));
            }
            $real = realpath($path);
            if (!is_string($real) || !str_starts_with(str_replace('\\', '/', $real), $realRoot . '/')) {
                throw new RuntimeException('File transaction path escapes the project root: ' . $this->display($realRoot, $path));
            }
            $hash = hash_file('sha256', $path);
            if (!is_string($hash) || !hash_equals((string) $operation->expectedSha256, $hash)) {
                throw new RuntimeException('File changed since it was read; re-read it and re-plan: ' . $this->display($realRoot, $path));
            }
        }
    }

    private function assertParentInside(string $realRoot, string $path): void
    {
        $probe = dirname($path);
        while (!is_dir($probe)) {
            if (file_exists($probe) || is_link($probe)) {
                throw new RuntimeException('Parent of a created file is not a directory: ' . $this->display($realRoot, $probe));
            }
            $next = dirname($probe);
            if ($next === $probe) {
                throw new RuntimeException('Unable to find an existing parent directory for: ' . $this->display($realRoot, $path));
            }
            $probe = $next;
        }
        $real = realpath($probe);
        $real = is_string($real) ? rtrim(str_replace('\\', '/', $real), '/') : '';
        if ($real !== $realRoot && !str_starts_with($real, $realRoot . '/')) {
            throw new RuntimeException('Parent of a created file escapes the project root: ' . $this->display($realRoot, $path));
        }
    }

    /** @return list<string> directories this call created, outermost first */
    private function createParents(string $realRoot, string $directory): array
    {
        $missing = [];
        for ($probe = $directory; !is_dir($probe); $probe = dirname($probe)) {
            $missing[] = $probe;
        }
        $created = [];
        foreach (array_reverse($missing) as $path) {
            if (!mkdir($path, 0o775) && !is_dir($path)) {
                throw new RuntimeException('Unable to create directory: ' . $this->display($realRoot, $path));
            }
            $created[] = $path;
        }

        return $created;
    }

    /**
     * @param list<FileOperation> $operations
     * @param array<string, string> $backups
     * @param array<string, true> $published
     * @return list<string>
     */
    private function rollback(array $operations, array $backups, array $published): array
    {
        $failures = [];
        foreach (array_reverse($operations) as $operation) {
            $path = $operation->path;
            if (isset($published[$path]) && (is_file($path) || is_link($path)) && !unlink($path)) {
                $failures[] = 'unable to remove published file ' . $path;
            }
            $backup = $backups[$path] ?? null;
            if ($backup === null) {
                continue;
            }
            if ((is_file($path) || is_link($path)) && !unlink($path)) {
                $failures[] = 'unable to clear file before restore ' . $path;
            }
            if ((is_file($backup) || is_link($backup)) && !$this->move($backup, $path)) {
                $failures[] = 'unable to restore backup of ' . $path;
            }
        }

        return $failures;
    }

    /**
     * @param list<string> $paths
     * @return list<string>
     */
    private function cleanupFiles(array $paths): array
    {
        $failures = [];
        foreach ($paths as $path) {
            if ((is_file($path) || is_link($path)) && !unlink($path)) {
                $failures[] = 'unable to remove temporary file ' . $path;
            }
        }

        return $failures;
    }

    /**
     * @param list<string> $directories outermost first
     * @return list<string>
     */
    private function cleanupDirectories(array $directories): array
    {
        $failures = [];
        foreach (array_reverse($directories) as $directory) {
            if (!is_dir($directory) || is_link($directory)) {
                continue;
            }
            $entries = scandir($directory);
            if (!is_array($entries) || $entries !== ['.', '..']) {
                $failures[] = 'unable to remove created directory because it is not empty: ' . $directory;
                continue;
            }
            if (!rmdir($directory)) {
                $failures[] = 'unable to remove created directory ' . $directory;
            }
        }

        return $failures;
    }

    private function temporaryPath(string $path, string $purpose): string
    {
        $temporary = $path . '.agent-edit-tx-' . $purpose . '-' . bin2hex(random_bytes(8));
        if (file_exists($temporary) || is_link($temporary)) {
            throw new RuntimeException('Transaction temporary path already exists: ' . $temporary);
        }

        return $temporary;
    }

    private function move(string $from, string $to): bool
    {
        if ($this->renameOperation !== null) {
            return ($this->renameOperation)($from, $to);
        }

        return rename($from, $to);
    }

    /** @return array{exit_code: int, stdout: string, stderr: string} */
    private function lint(string $path): array
    {
        if ($this->lintOperation !== null) {
            return ($this->lintOperation)($path);
        }

        $pipes = [];
        $process = proc_open([PHP_BINARY, '-n', '-l', $path], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start PHP syntax validation for staged source.');
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        return [
            'exit_code' => $exitCode,
            'stdout' => is_string($stdout) ? $stdout : '',
            'stderr' => is_string($stderr) ? $stderr : '',
        ];
    }

    private function display(string $realRoot, string $path): string
    {
        $path = str_replace('\\', '/', $path);

        return str_starts_with($path, $realRoot . '/') ? substr($path, strlen($realRoot) + 1) : $path;
    }
}
