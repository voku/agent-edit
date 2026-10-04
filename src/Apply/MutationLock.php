<?php

declare(strict_types=1);

namespace voku\AgentEdit\Apply;

use Closure;
use RuntimeException;

/** Serializes edit runners that may mutate one project working tree. */
final readonly class MutationLock
{
    /** @var (Closure(string, Closure): EditResult)|null */
    private ?Closure $synchronizeOperation;

    /** @param (Closure(string, Closure): EditResult)|null $synchronizeOperation */
    public function __construct(?Closure $synchronizeOperation = null)
    {
        $this->synchronizeOperation = $synchronizeOperation;
    }

    /** @param Closure(): EditResult $operation */
    public function synchronized(string $projectRoot, Closure $operation): EditResult
    {
        if ($this->synchronizeOperation !== null) {
            return ($this->synchronizeOperation)($projectRoot, $operation);
        }

        $root = realpath($projectRoot);
        if (!is_string($root)) {
            throw new RuntimeException('Unable to resolve project root for edit mutation lock: ' . $projectRoot);
        }

        $path = sys_get_temp_dir() . '/agent-edit-mutation-' . hash('sha256', $root) . '.lock';
        $handle = fopen($path, 'c');
        if (!is_resource($handle)) {
            throw new RuntimeException('Unable to open edit mutation lock: ' . $path);
        }
        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            throw new RuntimeException('Unable to acquire edit mutation lock: ' . $path);
        }

        try {
            return $operation();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
