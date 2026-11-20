<?php

declare(strict_types=1);

/**
 * Hidden oracle for the token-level rename cases. Usage: php token-rename.php CASE_ID FINAL_TREE BASELINE_TREE
 *
 * Builds the expected tree, then compares every PHP file of the baseline with the final tree by code tokens
 * (comments and whitespace ignored, so reformatting or re-commenting is not judged). Files the agent added are not
 * judged here; grade.py reports them as unintended paths. For `hierarchy-rename` it also executes both trees and
 * requires identical output. It executes candidate-written code: run it only in a disposable environment.
 *
 *   hierarchy-rename       expected tree from fixtures/hierarchy_rename.py (only the PriceSource family renamed)
 *   stale-rename           hierarchy-rename's expected tree plus the concurrent writer's method, its family call
 *                          renamed (fixtures/stale_writer.py adds it while the agent works)
 *   scale-rename           expected tree from fixtures/scale_rename.py (only the Ledger\Entry family renamed);
 *                          both trees also run the baseline's run.php
 *   recall-collect-rename  baseline with every identifier token `collect` replaced by `gather`
 */

[$_, $case, $final, $baseline] = $argv + [null, null, null, null];
if (!is_string($case) || !is_dir((string) $final) || !is_dir((string) $baseline)) {
    fwrite(STDERR, "usage: php token-rename.php CASE_ID FINAL_TREE BASELINE_TREE\n");
    exit(2);
}

/** Code tokens only; identifier tokens optionally renamed. */
$code = static function (string $source, ?array $rename = null): string {
    $out = [];
    foreach (PhpToken::tokenize($source) as $token) {
        if ($token->is([T_COMMENT, T_DOC_COMMENT, T_WHITESPACE])) {
            continue;
        }
        $out[] = $rename !== null && $token->is(T_STRING) && isset($rename[$token->text]) ? $rename[$token->text] : $token->text;
    }

    return implode(' ', $out);
};
$phpFiles = static function (string $root): array {
    $files = [];
    // config/ and checks/ exist only where a case's correctness depends on them (context-rename).
    foreach (['src', 'tests', 'config', 'checks'] as $dir) {
        if (!is_dir($root . '/' . $dir)) {
            continue;
        }
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir, FilesystemIterator::SKIP_DOTS)) as $file) {
            if (str_ends_with($file->getFilename(), '.php')) {
                $files[] = substr($file->getPathname(), strlen($root) + 1);
            }
        }
    }
    sort($files);

    return $files;
};

$expected = [];
switch ($case) {
    case 'hierarchy-rename':
    case 'stale-rename':
    case 'scale-rename':
    case 'context-rename':
        $tmp = sys_get_temp_dir() . '/route-oracle-' . bin2hex(random_bytes(6));
        $generator = ['scale-rename' => 'scale_rename.py', 'context-rename' => 'context_rename.py'][$case] ?? 'hierarchy_rename.py';
        // stale-rename: the writer's concurrent method must survive, with its family call renamed too.
        $variant = $case === 'stale-rename' ? 'expected-stale' : 'expected';
        // context-rename: the context arms' tree carries MEMORY.md and the #[Rule] annotations.
        $flags = $case === 'context-rename' && is_file($baseline . '/MEMORY.md') ? ' --context' : '';
        exec('python3 ' . escapeshellarg(__DIR__ . '/../fixtures/' . $generator) . ' ' . escapeshellarg($tmp) . ' ' . $variant . $flags, $_out, $status);
        if ($status !== 0) {
            fwrite(STDERR, "fixture generator failed\n");
            exit(2);
        }
        foreach ($phpFiles($tmp) as $path) {
            $expected[$path] = $code((string) file_get_contents($tmp . '/' . $path));
        }
        exec('rm -rf ' . escapeshellarg($tmp));
        break;
    case 'recall-collect-rename':
        foreach ($phpFiles($baseline) as $path) {
            $expected[$path] = $code((string) file_get_contents($baseline . '/' . $path), ['collect' => 'gather']);
        }
        break;
    default:
        fwrite(STDERR, "unknown case {$case}\n");
        exit(2);
}

$wrong = [];
$missing = [];
foreach ($expected as $path => $tokens) {
    if (!is_file($final . '/' . $path)) {
        $missing[] = $path;
    } elseif ($code((string) file_get_contents($final . '/' . $path)) !== $tokens) {
        $wrong[] = $path;
    }
}
$checks = [
    'every baseline PHP file still exists' => $missing === [],
    'every PHP file matches the expected rename by code tokens' => $wrong === [],
];

$observed = null;
if (in_array($case, ['hierarchy-rename', 'stale-rename', 'context-rename'], true)) {
    $gateway = $case === 'context-rename'
        ? ' echo "|", json_encode((new Shop\\Gateway\\PaymentGateway(require getcwd() . "/config/gateway.php"))->charge([$catalog->product(2), $catalog->bundle()]));'
        : '';
    $script = <<<'PHP'
spl_autoload_register(static function (string $class): void {
    $file = getcwd() . '/src/' . str_replace('\\', '/', substr($class, strlen('Shop\\'))) . '.php';
    if (is_file($file)) { require $file; }
});
$catalog = new Shop\Repo\Catalog();
$promotions = new Shop\Repo\Promotions();
echo implode('|', [
    (new Shop\Checkout\Cart($catalog, $promotions))->due(3, 'SAVE'),
    (new Shop\Checkout\Quote($catalog, $promotions))->covered('support'),
    (new Shop\Checkout\Quote($catalog, $promotions))->covered('plan'),
    (new Shop\Checkout\Totals())->net([$catalog->product(1), $catalog->bundle()], [$promotions->giftCard()]),
    ((new Shop\Checkout\Refund($catalog))->quoter())(),
    json_encode((new Shop\Checkout\Display($catalog))->line(true)),
    json_encode((new Shop\Checkout\Display($catalog))->line(false)),
    (new Shop\Report\Ledger())->book($catalog->source('plan'), $promotions->coupon('X')),
]);
PHP;
    $script .= $gateway;
    $run = static function (string $root) use ($script): string {
        $output = [];
        exec('cd ' . escapeshellarg($root) . ' && php -r ' . escapeshellarg($script) . ' 2>&1', $output, $status);

        return $status === 0 ? implode("\n", $output) : 'error: ' . implode("\n", $output);
    };
    $want = $run($baseline);
    $observed = $run($final);
    $checks['behaviour unchanged'] = !str_starts_with($want, 'error') && $observed === $want;
}
if ($case === 'scale-rename') {
    // The baseline's own run.php drives both trees, so an edited harness cannot change the verdict. $final is the
    // grader's scratch copy, so overwriting its run.php touches nothing the agent left behind.
    copy($baseline . '/run.php', $final . '/run.php');
    $run = static function (string $root): string {
        $output = [];
        exec('cd ' . escapeshellarg($root) . ' && php run.php 2>&1', $output, $status);

        return $status === 0 ? implode("\n", $output) : 'error: ' . implode("\n", $output);
    };
    $want = $run($baseline);
    $observed = $run($final);
    $checks['behaviour unchanged'] = !str_starts_with($want, 'error') && $observed === $want;
}

echo json_encode([
    'correct' => !in_array(false, $checks, true),
    'checks' => $checks,
    'wrong_files' => $wrong,
    'missing_files' => $missing,
    'behaviour' => $observed,
], JSON_UNESCAPED_SLASHES) . "\n";
