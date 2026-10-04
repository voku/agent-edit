<?php

declare(strict_types=1);

// Child process used by MutationLockTwoProcessTest: takes the real MutationLock, records enter/exit in a shared log
// and holds the lock for a while. Usage: php mutation-lock-worker.php <root> <log> <name> <hold-ms> [held-marker-file]

use voku\AgentEdit\Apply\EditResult;
use voku\AgentEdit\Apply\MutationLock;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

[, $root, $log, $name, $holdMs] = $argv;
$heldMarker = $argv[5] ?? '';

$record = static function (string $line) use ($log): void {
    file_put_contents($log, $line . "\n", FILE_APPEND | LOCK_EX);
};

$record('attempt ' . $name);
(new MutationLock())->synchronized($root, static function () use ($record, $name, $holdMs, $heldMarker): EditResult {
    $record('enter ' . $name);
    if ($heldMarker !== '') {
        touch($heldMarker);
    }
    usleep((int) $holdMs * 1000);
    $record('exit ' . $name);

    return new EditResult('prepared', 0);
});
