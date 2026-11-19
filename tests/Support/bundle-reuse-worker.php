<?php

declare(strict_types=1);

// Child process used by ReceiptBundleTwoProcessTest: applies one direct-edit plan with a receipt into a shared bundle.
// With a held marker, it announces from inside host authorization (after claiming the bundle) and waits there until
// the release marker exists, so a second process can try the same bundle in that window.
// Usage: php bundle-reuse-worker.php <root> <plan> <bundle> [held-marker release-marker]

use voku\AgentEdit\EditEngine;
use voku\AgentEdit\Receipt\ApplyRequest;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$arguments = $_SERVER['argv'] ?? null;
if (!is_array($arguments) || !is_string($arguments[1] ?? null) || !is_string($arguments[2] ?? null) || !is_string($arguments[3] ?? null)) {
    throw new RuntimeException('Bundle reuse worker requires root, plan and bundle arguments.');
}
[, $root, $plan, $bundle] = $arguments;
$held = is_string($arguments[4] ?? null) ? $arguments[4] : null;
$release = is_string($arguments[5] ?? null) ? $arguments[5] : null;

$authorize = $held === null || $release === null ? null : static function () use ($held, $release): void {
    file_put_contents($held, 'held');
    $deadline = microtime(true) + 20;
    while (!is_file($release) && microtime(true) < $deadline) {
        usleep(10_000);
    }
};

try {
    $receipt = (new EditEngine())->applyWithReceipt(new ApplyRequest(
        repositoryRoot: $root,
        planPath: $plan,
        mapIndexPath: null,
        mapRoot: $root,
        outputDirectory: $bundle,
        label: 'shared',
        authorizeMutation: $authorize,
    ));
    fwrite(STDOUT, $receipt->status);
    exit($receipt->status === 'runner_succeeded' ? 0 : 1);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage());
    exit(2);
}
