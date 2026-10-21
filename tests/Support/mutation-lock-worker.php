<?php

declare(strict_types=1);

// Child process used by MutationLockTwoProcessTest: takes the real MutationLock, records enter/exit in a shared log
// and holds the lock for a while. Usage: php mutation-lock-worker.php <root> <log> <name> <hold-ms> [held-marker-file]

use voku\AgentEdit\Apply\EditResult;
use voku\AgentEdit\Apply\MutationLock;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$arguments = $_SERVER['argv'] ?? null;
if (!is_array($arguments)
    || !is_string($arguments[1] ?? null)
    || !is_string($arguments[2] ?? null)
    || !is_string($arguments[3] ?? null)
    || !is_string($arguments[4] ?? null)
) {
    throw new RuntimeException('Mutation lock worker requires root, log, name and hold-ms arguments.');
}
$root = $arguments[1];
$log = $arguments[2];
$name = $arguments[3];
$holdMs = $arguments[4];
$heldMarker = $arguments[5] ?? '';
if (!is_string($heldMarker)) {
    throw new RuntimeException('Mutation lock worker held-marker-file must be a string.');
}

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
