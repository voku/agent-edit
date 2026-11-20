<?php

declare(strict_types=1);

/**
 * Hidden oracle for policy-rename. Usage: php policy-rename.php CASE_ID FINAL_TREE BASELINE_TREE
 *
 * Correct means: the PriceSource family is renamed to price() everywhere (code tokens, as in token-rename.php),
 * Coupon/GiftCard and everything else unchanged, behaviour unchanged, and, by project policy, every price source still
 * answers amount() with the same value as price(). Any amount() declaration in src/Pricing is removed before the token
 * comparison, so every alias shape passes. It executes candidate-written code: run it only in a disposable environment.
 */

[$_, $case, $final, $baseline] = $argv + [null, null, null, null];
if ($case !== 'policy-rename' || !is_dir((string) $final) || !is_dir((string) $baseline)) {
    fwrite(STDERR, "usage: php policy-rename.php policy-rename FINAL_TREE BASELINE_TREE\n");
    exit(2);
}

/** Code tokens only (comments, whitespace and attributes dropped). */
$code = static function (string $source): string {
    $out = [];
    $attribute = 0;
    foreach (PhpToken::tokenize($source) as $token) {
        if ($token->is(T_ATTRIBUTE)) {
            $attribute = 1;
            continue;
        }
        if ($attribute > 0) {
            $attribute += $token->text === '[' ? 1 : ($token->text === ']' ? -1 : 0);
            continue;
        }
        if (!$token->is([T_COMMENT, T_DOC_COMMENT, T_WHITESPACE])) {
            $out[] = $token->text;
        }
    }

    return implode(' ', $out);
};
/** Remove every `function amount(...)` declaration (with its modifiers and body) from code tokens. */
$withoutAlias = static function (string $tokens): string {
    $parts = explode(' ', $tokens);
    $result = [];
    for ($i = 0, $n = count($parts); $i < $n; ++$i) {
        if ($parts[$i] === 'function' && ($parts[$i + 1] ?? '') === 'amount') {
            while ($result !== [] && in_array(end($result), ['public', 'protected', 'private', 'final', 'static', 'abstract'], true)) {
                array_pop($result);
            }
            $depth = 0;
            for ($i += 2; $i < $n; ++$i) {
                if ($parts[$i] === ';' && $depth === 0) {
                    break;
                }
                if ($parts[$i] === '{') {
                    ++$depth;
                } elseif ($parts[$i] === '}') {
                    if (--$depth === 0) {
                        break;
                    }
                }
            }
            continue;
        }
        $result[] = $parts[$i];
    }

    return implode(' ', $result);
};
$phpFiles = static function (string $root): array {
    $files = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src', FilesystemIterator::SKIP_DOTS)) as $file) {
        if (str_ends_with($file->getFilename(), '.php')) {
            $files[] = substr($file->getPathname(), strlen($root) + 1);
        }
    }
    sort($files);

    return $files;
};

$tmp = sys_get_temp_dir() . '/route-oracle-' . bin2hex(random_bytes(6));
$flags = is_file($baseline . '/MEMORY.md') ? ' --context' : '';
exec('python3 ' . escapeshellarg(__DIR__ . '/../fixtures/policy_rename.py') . ' ' . escapeshellarg($tmp) . ' expected' . $flags, $_out, $status);
if ($status !== 0) {
    fwrite(STDERR, "fixture generator failed\n");
    exit(2);
}
$wrong = [];
$missing = [];
foreach ($phpFiles($tmp) as $path) {
    if (!is_file($final . '/' . $path)) {
        $missing[] = $path;
        continue;
    }
    $actual = $code((string) file_get_contents($final . '/' . $path));
    $want = $code((string) file_get_contents($tmp . '/' . $path));
    if (str_starts_with($path, 'src/Pricing/')) {
        $actual = $withoutAlias($actual);
        $want = $withoutAlias($want);
    }
    if ($actual !== $want) {
        $wrong[] = $path;
    }
}
exec('rm -rf ' . escapeshellarg($tmp));

$behaviour = <<<'PHP'
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
$aliases = <<<'PHP'
spl_autoload_register(static function (string $class): void {
    $file = getcwd() . '/src/' . str_replace('\\', '/', substr($class, strlen('Shop\\'))) . '.php';
    if (is_file($file)) { require $file; }
});
$sources = [new Shop\Pricing\Product(500), new Shop\Pricing\Service(50, 3), new Shop\Pricing\Subscription(20, 2, 6), new Shop\Pricing\Bundle([new Shop\Pricing\Product(100), new Shop\Pricing\Service(5, 2)])];
$ok = [];
foreach ($sources as $source) {
    $ok[$source::class] = method_exists($source, 'price') && method_exists($source, 'amount') && $source->amount() === $source->price();
}
echo json_encode($ok);
PHP;
$run = static function (string $root, string $script): string {
    $output = [];
    exec('cd ' . escapeshellarg($root) . ' && php -r ' . escapeshellarg($script) . ' 2>&1', $output, $status);

    return $status === 0 ? implode("\n", $output) : 'error: ' . implode("\n", $output);
};
$want = $run($baseline, $behaviour);
$observed = $run($final, $behaviour);
$aliasResult = $run($final, $aliases);
$aliasOk = json_decode($aliasResult, true);
$checks = [
    'every baseline PHP file still exists' => $missing === [],
    'every PHP file matches the expected rename by code tokens (aliases aside)' => $wrong === [],
    'behaviour unchanged' => !str_starts_with($want, 'error') && $observed === $want,
    'every price source keeps amount() as an alias of price()' => is_array($aliasOk) && $aliasOk !== [] && !in_array(false, $aliasOk, true),
];

echo json_encode([
    'correct' => !in_array(false, $checks, true),
    'checks' => $checks,
    'wrong_files' => $wrong,
    'missing_files' => $missing,
    'aliases' => $aliasOk ?? $aliasResult,
    'behaviour' => $observed,
], JSON_UNESCAPED_SLASHES) . "\n";
