<?php

declare(strict_types=1);

namespace voku\AgentEdit\Tests\Support;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/** An isolated temporary project root for tests; `removeScratchProject()` deletes it again. */
trait ScratchProject
{
    private string $root = '';

    private function createScratchProject(string $prefix): void
    {
        $root = sys_get_temp_dir() . '/' . $prefix . bin2hex(random_bytes(6));
        mkdir($root, 0o775, true);
        // The appliers resolve paths through realpath(); keep the same spelling.
        $this->root = (string) realpath($root);
    }

    private function write(string $relative, string $content): void
    {
        $path = $this->root . '/' . $relative;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0o775, true);
        }
        file_put_contents($path, $content);
    }

    private function read(string $relative): string
    {
        return (string) file_get_contents($this->root . '/' . $relative);
    }

    private function removeScratchProject(): void
    {
        if ($this->root === '' || !is_dir($this->root)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->root);
    }
}
