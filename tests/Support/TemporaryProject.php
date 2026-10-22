<?php

declare(strict_types=1);

namespace voku\AgentEdit\Tests\Support;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/** A throw-away project directory for tests that write real files. */
final class TemporaryProject
{
    public readonly string $root;

    /** @param array<string, string> $files relative path => content */
    public function __construct(array $files = [])
    {
        $path = str_replace('\\', '/', sys_get_temp_dir()) . '/agent-edit-test-' . bin2hex(random_bytes(6));
        mkdir($path, 0o775, true);
        $real = realpath($path);
        $this->root = is_string($real) ? str_replace('\\', '/', $real) : $path;
        foreach ($files as $path => $content) {
            $this->write($path, $content);
        }
    }

    public function path(string $relative): string
    {
        return $this->root . '/' . $relative;
    }

    public function write(string $relative, string $content): void
    {
        $path = $this->path($relative);
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0o775, true);
        }
        file_put_contents($path, $content);
    }

    public function read(string $relative): string
    {
        return (string) file_get_contents($this->path($relative));
    }

    public function hash(string $relative): string
    {
        return 'sha256:' . hash_file('sha256', $this->path($relative));
    }

    /**
     * Every file below the root, relative and sorted, so a test can assert that nothing else was left behind.
     *
     * @return list<string>
     */
    public function files(): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($iterator as $item) {
            if ($item->isFile() || $item->isLink()) {
                $files[] = substr(str_replace('\\', '/', $item->getPathname()), strlen($this->root) + 1);
            }
        }
        sort($files);

        return $files;
    }

    /** @return list<string> */
    public function directories(): array
    {
        $directories = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                $directories[] = substr(str_replace('\\', '/', $item->getPathname()), strlen($this->root) + 1);
            }
        }
        sort($directories);

        return $directories;
    }

    public function remove(): void
    {
        if (!is_dir($this->root)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->root);
    }
}
