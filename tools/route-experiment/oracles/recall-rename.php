<?php

declare(strict_types=1);

/**
 * Hidden oracle for recall-rename. Usage: php recall-rename.php CASE_ID FINAL_TREE BASELINE_TREE
 * The suite itself (cases.json test_command) is run by grade.py; this checks the identity change and that every
 * changed PHP line differs from the baseline only by the renamed identifier.
 */

[$_, $case, $final, $baseline] = $argv + [null, null, null, null];
$checks = [];
$old = 'toRecallResult';
$new = 'asRecallResult';
$left = [];
$foreign = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($final . '/src', FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if (!str_ends_with($file->getFilename(), '.php')) {
        continue;
    }
    $relative = substr($file->getPathname(), strlen($final) + 1);
    $after = (string) file_get_contents($file->getPathname());
    if (str_contains($after, $old)) {
        $left[] = $relative;
    }
    $before = (string) @file_get_contents($baseline . '/' . $relative);
    if ($before !== $after && str_replace($old, $new, $before) !== $after) {
        $foreign[] = $relative;
    }
}
$selection = (string) @file_get_contents($final . '/src/SelectionResult.php');
$checks['declares asRecallResult()'] = (bool) preg_match('/public\s+function\s+asRecallResult\s*\(\s*\)\s*:\s*RecallResult/', $selection);
$checks['no toRecallResult left in src'] = $left === [];
$callers = 0;
foreach (['MetaJsonRenderer', 'OutcomeDraftRenderer', 'SystemBriefingRenderer', 'ValidationPlanRenderer'] as $class) {
    $callers += (int) str_contains((string) @file_get_contents($final . '/src/' . $class . '.php'), '->asRecallResult()');
}
$checks['all four callers updated'] = $callers === 4;

// Correctness is the identity change (plus the suite, run by grade.py). Source changes beyond the rename are
// reported separately as unintended changes, not folded into correctness.
echo json_encode(['correct' => !in_array(false, $checks, true), 'checks' => $checks, 'left' => $left, 'unintended_src_files' => $foreign], JSON_UNESCAPED_SLASHES) . "\n";
