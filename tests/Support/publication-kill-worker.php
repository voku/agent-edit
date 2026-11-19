<?php

declare(strict_types=1);

// Child process used by PublicationCrashRecoveryTest. It applies a plan through the real EditEngine and publishers,
// but counts the publisher's renames: right after rename number <hold-after-renames> it writes <held-marker> and
// blocks, so the parent can SIGKILL it at an exact point of the publication (no cleanup, no rollback, no receipt).
// Usage: php publication-kill-worker.php <root> <plan> <bundle> <direct|map> <hold-after-renames> <held-marker> [map-index]

use voku\AgentEdit\Apply\RenamePlanApplier;
use voku\AgentEdit\Apply\SourceEditPlanApplier;
use voku\AgentEdit\EditEngine;
use voku\AgentEdit\Receipt\ApplyRequest;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$arguments = $_SERVER['argv'] ?? null;
if (!is_array($arguments) || count($arguments) < 7) {
    throw new RuntimeException('Publication kill worker requires root, plan, bundle, mode, hold-after-renames and held-marker.');
}
[, $root, $plan, $bundle, $mode, $holdAfter, $marker] = array_map('strval', $arguments);
$mapIndex = isset($arguments[7]) ? (string) $arguments[7] : null;

$renames = 0;
$hold = static function (string $from, string $to) use (&$renames, $holdAfter, $marker): bool {
    $moved = rename($from, $to);
    if (++$renames === (int) $holdAfter) {
        file_put_contents($marker, 'held after rename ' . $renames);
        sleep(120); // the parent kills this process here
    }

    return $moved;
};

$engine = new EditEngine(applierOverrides: $mode === 'map'
    ? [RenamePlanApplier::class => new RenamePlanApplier($hold)]
    : [SourceEditPlanApplier::class => new SourceEditPlanApplier($hold)]);

$receipt = $engine->applyWithReceipt(new ApplyRequest(
    repositoryRoot: $root,
    planPath: $plan,
    mapIndexPath: $mapIndex,
    mapRoot: $root,
    outputDirectory: $bundle,
    label: 'crash',
));
fwrite(STDOUT, $receipt->status);
exit(0);
