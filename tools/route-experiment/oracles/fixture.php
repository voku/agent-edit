<?php

declare(strict_types=1);

/**
 * Hidden oracle for the benchmark-fixture cases. Usage: php fixture.php CASE_ID FINAL_TREE BASELINE_TREE
 * Prints one JSON verdict: correct, checks (name => bool) and the observed behaviour.
 * It executes candidate-written code: run it only in a disposable environment.
 */

[$_, $case, $final, $baseline] = $argv + [null, null, null, null];
if (!is_string($case) || !is_dir((string) $final) || !is_dir((string) $baseline)) {
    fwrite(STDERR, "usage: php fixture.php CASE_ID FINAL_TREE BASELINE_TREE\n");
    exit(2);
}

$read = static fn (string $root, string $path): string => (string) @file_get_contents($root . '/' . $path);
$behaviour = static function (string $root, string $totalMethod): string {
    $script = 'foreach (glob("src/*.php") as $file) { require $file; }'
        . ' $invoice = new Shop\Invoice(5, -2, 7); $cart = new Shop\Cart([3, 4]);'
        . ' echo $invoice->' . $totalMethod . '(), "|", (new Shop\Checkout())->due($invoice, $cart), "|", (new Shop\Report())->line(["total" => 9]), "|", json_encode($invoice->summary()), "|", $cart->total(), "|", $cart->isEmpty() ? "empty" : "items";';
    $output = [];
    exec('cd ' . escapeshellarg($root) . ' && php -r ' . escapeshellarg($script) . ' 2>&1', $output, $code);

    return $code === 0 ? implode("\n", $output) : 'error';
};
// Strips comments and whitespace so a reformatted or re-commented file is judged by its code.
$code = static function (string $source): string {
    $out = '';
    foreach (PhpToken::tokenize($source) as $token) {
        if (!$token->is([T_COMMENT, T_DOC_COMMENT, T_WHITESPACE])) {
            $out .= $token->text . ' ';
        }
    }

    return $out;
};

$invoice = $read($final, 'src/Invoice.php');
$checks = [];
switch ($case) {
    case 'fixture-rename':
        $checks['declares grandTotal()'] = (bool) preg_match('/public\s+function\s+grandTotal\s*\(\s*\)\s*:\s*int/', $invoice);
        $checks['no Invoice::total() left'] = !preg_match('/function\s+total\s*\(/', $invoice) && !str_contains($invoice, '$this->total()');
        $checks['Cart::total() untouched'] = $code($read($final, 'src/Cart.php')) === $code($read($baseline, 'src/Cart.php'));
        $checks['Checkout calls both correctly'] = str_contains($read($final, 'src/Checkout.php'), '$invoice->grandTotal()') && str_contains($read($final, 'src/Checkout.php'), '$cart->total()');
        $checks['SQL string and array key untouched'] = str_contains($read($final, 'src/Report.php'), "'SELECT total FROM invoices'") && str_contains($read($final, 'src/Report.php'), "\$row['total']");
        $want = '10|17|total=9 check=3|{"total":10,"count":3}|7|items';
        $checks['behaviour'] = $behaviour($final, 'grandTotal') === $want;
        break;
    case 'fixture-remove':
        $checks['legacyTotal() removed'] = !str_contains($invoice, 'legacyTotal');
        $checks['rest of Invoice unchanged'] = $code(preg_replace('/private\s+function\s+legacyTotal.*?\n    }\n/s', '', $read($baseline, 'src/Invoice.php'))) === $code($invoice);
        $checks['behaviour'] = $behaviour($final, 'total') === '10|17|total=9 check=3|{"total":10,"count":3}|7|items';
        break;
    case 'fixture-body':
        $checks['signature kept'] = (bool) preg_match('/public\s+function\s+total\s*\(\s*\)\s*:\s*int/', $invoice);
        $checks['behaviour'] = $behaviour($final, 'total') === '12|19|total=9 check=3|{"total":12,"count":3}|7|items';
        break;
    default:
        fwrite(STDERR, "unknown case {$case}\n");
        exit(2);
}

echo json_encode(['correct' => !in_array(false, $checks, true), 'checks' => $checks], JSON_UNESCAPED_SLASHES) . "\n";
