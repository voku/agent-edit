<?php

declare(strict_types=1);

/**
 * Route benchmark: the same tasks through the routes a coding agent can take, measured mechanically.
 *
 *   native-ideal  exact old/new string edits, the smallest unique `old` per changed hunk (a perfect native editor)
 *   native-naive  a plausible shortcut: replace the method-call text in every file that contains it
 *   direct        agent-edit source_edit_plan (symbol operations or anchored text), applied for real
 *   map+edit      agent-map plan -> agent-edit apply, applied for real
 *
 * Every route that runs is checked against a hand-written expected tree and by executing the code. Per route it
 * reports whether the result is correct, how many files changed that the task did not touch, the bytes an agent has
 * to read and to write (author) to perform it, and the tool calls. A route that does not fit a task is "n/a": a Map
 * plan for a body change, or a direct edit for a cross-file rename, would be the wrong tool and bias the comparison.
 *
 * What this does NOT measure: model behaviour (tokens a real agent spends deciding, its mistakes, retries). The native
 * rows are simulated editors, the ideal one making no mistakes at all. The numbers are lower bounds for the mechanical
 * part of each route, not agent productivity. See README "Route benchmark".
 *
 * Usage: php tools/route-benchmark.php [--json] [--check]
 *   --check  exit 1 unless the fixed expectations hold (CI keeps the published table honest)
 */

$root = dirname(__DIR__);
$mapBin = $root . '/vendor/bin/agent-map';
$editBin = $root . '/bin/agent-edit';
$json = in_array('--json', $argv, true);
$check = in_array('--check', $argv, true);

/** @return array{0: int, 1: string} */
function run(string $dir, string $command): array
{
    $lines = [];
    exec('cd ' . escapeshellarg($dir) . ' && ' . $command . ' 2>&1', $lines, $code);

    return [$code, implode("\n", $lines) . ($lines === [] ? '' : "\n")];
}

function php(string $body): string
{
    return "<?php\n\ndeclare(strict_types=1);\n\nnamespace Shop;\n\n" . $body . "\n";
}

/** @return array<string, string> */
function fixture(): array
{
    return [
        'src/Invoice.php' => php(<<<'PHP'
final class Invoice
{
    /** @var list<int> */
    private array $lines;

    public function __construct(int ...$lines)
    {
        $this->lines = $lines;
    }

    public function total(): int
    {
        return array_sum($this->lines);
    }

    private function legacyTotal(): int
    {
        $sum = 0;
        foreach ($this->lines as $line) {
            $sum += $line;
        }

        return $sum;
    }

    /** @return array{total: int, count: int} */
    public function summary(): array
    {
        return ['total' => $this->total(), 'count' => count($this->lines)];
    }
}
PHP),
        'src/Cart.php' => php(<<<'PHP'
final class Cart
{
    /** @param list<int> $prices */
    public function __construct(private array $prices)
    {
    }

    public function total(): int
    {
        return array_sum($this->prices);
    }

    public function isEmpty(): bool
    {
        return $this->total() === 0;
    }
}
PHP),
        'src/Checkout.php' => php(<<<'PHP'
final class Checkout
{
    // total() of an invoice is net; the cart total() is gross.
    public function due(Invoice $invoice, Cart $cart): int
    {
        return $invoice->total() + $cart->total();
    }
}
PHP),
        'src/Report.php' => php(<<<'PHP'
final class Report
{
    public const QUERY = 'SELECT total FROM invoices';

    /** @param array{total: int} $row */
    public function line(array $row): string
    {
        return 'total=' . $row['total'] . ' check=' . (new Invoice(1, 2))->total();
    }
}
PHP),
    ];
}

/** Runs the code of a tree and returns what it prints, or the error. */
function behaviour(string $dir): string
{
    $script = 'foreach (glob("src/*.php") as $file) { require $file; }'
        . ' $invoice = new Shop\Invoice(5, -2, 7); $cart = new Shop\Cart([3, 4]);'
        . ' echo (new Shop\Checkout())->due($invoice, $cart), "|", (new Shop\Report())->line(["total" => 9]), "|", json_encode($invoice->summary()), "|", $cart->isEmpty() ? "empty" : "items";';
    [$code, $out] = run($dir, 'php -r ' . escapeshellarg($script));

    return $code === 0 ? trim($out) : 'error: ' . strtok(trim($out), "\n");
}

/**
 * The tasks. `expected` maps every file the task changes to its expected content (absent: deleted); everything
 * else must stay byte-identical. `routes` holds the applicable routes as closures over a working directory.
 */
function tasks(string $mapBin, string $editBin): array
{
    $base = fixture();
    $renamed = $base;
    $renamed['src/Invoice.php'] = str_replace(['public function total()', '$this->total()'], ['public function grandTotal()', '$this->grandTotal()'], $base['src/Invoice.php']);
    $renamed['src/Checkout.php'] = str_replace('$invoice->total()', '$invoice->grandTotal()', $base['src/Checkout.php']);
    $renamed['src/Report.php'] = str_replace('(new Invoice(1, 2))->total()', '(new Invoice(1, 2))->grandTotal()', $base['src/Report.php']);

    $body = $base;
    $body['src/Invoice.php'] = str_replace('        return array_sum($this->lines);', '        return array_sum(array_filter($this->lines, static fn (int $line): bool => $line > 0));', $base['src/Invoice.php']);

    $removed = $base;
    $removed['src/Invoice.php'] = preg_replace('/    private function legacyTotal\(\): int\n.*?\n    }\n\n/s', '', $base['src/Invoice.php']);

    $edit = static fn (string $plan): string => 'printf %s ' . escapeshellarg($plan) . ' | ' . escapeshellarg($editBin) . ' apply - --task T --format=json';
    $mapPlan = static fn (string $command): array => [
        escapeshellarg($mapBin) . ' build --root=. --paths=src --out=.agent-map/php-symbols.json',
        escapeshellarg($mapBin) . ' ' . $command . ' --index .agent-map/php-symbols.json --format json > plan.json',
        escapeshellarg($editBin) . ' apply plan.json --task T --format=json',
    ];

    return [
        'rename' => [
            'title' => 'Rename Invoice::total() to grandTotal() (3 call sites in 3 files; Cart::total(), a SQL string, an array key and a comment must stay)',
            'expected' => array_diff_assoc($renamed, $base),
            'needle' => 'total',
            'naive' => static function (array $files): array {
                foreach ($files as $path => $content) {
                    if (str_contains($content, '->total()')) {
                        $files[$path] = str_replace('->total()', '->grandTotal()', $content);
                    }
                }
                $files['src/Invoice.php'] = str_replace('public function total()', 'public function grandTotal()', $files['src/Invoice.php']);

                return $files;
            },
            'direct' => null,
            'map' => $mapPlan("rename-plan 'Shop\\Invoice::total' grandTotal"),
        ],
        'body' => [
            'title' => 'Change the body of Invoice::total() to ignore negative lines (one method, one file)',
            'expected' => array_diff_assoc($body, $base),
            'needle' => null,
            'naive' => null,
            'direct' => [$edit(json_encode(['type' => 'source_edit_plan', 'contract_version' => '1.0', 'target_id' => 'body', 'edits' => [[
                'op' => 'replace_body', 'path' => 'src/Invoice.php', 'symbol' => 'Shop\Invoice::total()',
                'code' => 'return array_sum(array_filter($this->lines, static fn (int $line): bool => $line > 0));',
            ]]], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR))],
            'map' => null,
        ],
        'remove' => [
            'title' => 'Remove the unused private method Invoice::legacyTotal() (9 lines)',
            'expected' => array_diff_assoc($removed, $base),
            'needle' => 'legacyTotal',
            'naive' => null,
            'direct' => [$edit(json_encode(['type' => 'source_edit_plan', 'contract_version' => '1.0', 'target_id' => 'remove', 'edits' => [[
                'op' => 'remove_symbol', 'path' => 'src/Invoice.php', 'symbol' => 'Shop\Invoice::legacyTotal()',
            ]]], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR))],
            'map' => $mapPlan("method-removal-plan 'Shop\\Invoice::legacyTotal'"),
        ],
    ];
}

/** @param array<string, string> $files */
function materialize(array $files): string
{
    $dir = sys_get_temp_dir() . '/agent-edit-route-benchmark-' . bin2hex(random_bytes(6));
    mkdir($dir . '/src', 0o775, true);
    foreach ($files as $path => $content) {
        file_put_contents($dir . '/' . $path, $content);
    }
    run($dir, 'git init -q . && printf ".agent-map/\n.agent-edit/\nplan.json\n" > .gitignore && git add -A && git -c user.email=b@example.invalid -c user.name=b commit -qm init');

    return $dir;
}

/** @return array<string, string> */
function tree(string $dir): array
{
    $files = [];
    foreach (glob($dir . '/src/*.php') ?: [] as $file) {
        $files['src/' . basename($file)] = (string) file_get_contents($file);
    }
    ksort($files);

    return $files;
}

function remove(string $dir): void
{
    run(sys_get_temp_dir(), 'rm -rf ' . escapeshellarg($dir));
}

/**
 * The smallest old/new pairs a native exact-string editor needs: one per changed hunk, each `old` grown by whole
 * lines until it occurs exactly once in the file.
 *
 * @return list<array{old: string, new: string}>
 */
function nativeEdits(string $before, string $after): array
{
    $a = explode("\n", $before);
    $b = explode("\n", $after);
    $edits = [];
    $i = 0;
    $j = 0;
    while ($i < count($a) || $j < count($b)) {
        if ($i < count($a) && $j < count($b) && $a[$i] === $b[$j]) {
            ++$i;
            ++$j;
            continue;
        }
        // Find the next resynchronisation point (smallest combined skip).
        $best = null;
        for ($di = 0; $i + $di <= count($a) && $best === null; ++$di) {
            for ($dj = 0; $j + $dj <= count($b); ++$dj) {
                if (($i + $di === count($a) && $j + $dj === count($b)) || ($i + $di < count($a) && $j + $dj < count($b) && $a[$i + $di] === $b[$j + $dj])) {
                    $best = [$di, $dj];
                    break;
                }
            }
        }
        [$di, $dj] = $best ?? [count($a) - $i, count($b) - $j];
        $from = $i;
        $to = $i + $di;
        $old = implode("\n", array_slice($a, $from, $to - $from));
        $new = implode("\n", array_slice($b, $j, $dj));
        // Grow with context until the old text is unique (an empty old text, an insertion, always needs context).
        $up = 0;
        $down = 0;
        while ($old === '' || substr_count($before, $old) !== 1) {
            $grow = ($up <= $down && $from - $up - 1 >= 0) || $to + $down >= count($a);
            if ($grow) {
                ++$up;
            } else {
                ++$down;
            }
            $old = implode("\n", array_slice($a, $from - $up, $to - $from + $up + $down));
            $new = implode("\n", [...array_slice($a, $from - $up, $up), ...array_slice($b, $j, $dj), ...array_slice($a, $to, $down)]);
        }
        $edits[] = ['old' => $old, 'new' => $new];
        $i += $di;
        $j += $dj;
    }

    return $edits;
}

/**
 * @param array<string, string> $base
 * @param array<string, string> $result
 * @param array<string, string> $expected
 * @return array{correct: bool, unrelated_files: int, behaviour: string}
 */
function judge(array $base, array $result, array $expected, string $dir, string $wantBehaviour): array
{
    $want = array_replace($base, $expected);
    ksort($want);
    $unrelated = 0;
    foreach ($result as $path => $content) {
        if (!isset($expected[$path]) && ($base[$path] ?? null) !== $content) {
            ++$unrelated;
        }
    }
    $behaviour = behaviour($dir);

    return ['correct' => $result === $want && $behaviour === $wantBehaviour, 'unrelated_files' => $unrelated, 'behaviour' => $behaviour];
}

$rows = [];
$base = fixture();
foreach (tasks($mapBin, $editBin) as $id => $task) {
    $want = array_replace($base, $task['expected']);
    $wantDir = materialize($want);
    $wantBehaviour = behaviour($wantDir);
    remove($wantDir);

    // native-ideal: grep (when the task needs to find usages), read every file it names, one exact edit per hunk.
    $readFiles = $task['needle'] === null ? array_keys($task['expected']) : array_keys(array_filter($base, static fn (string $c): bool => str_contains($c, $task['needle'])));
    $grepBytes = $task['needle'] === null ? 0 : strlen(implode("\n", array_map(static fn (string $p): string => $p . ':' . substr_count($base[$p], $task['needle']), $readFiles)));
    $readBytes = $grepBytes + array_sum(array_map(static fn (string $p): int => strlen($base[$p]), $readFiles));
    $edits = [];
    foreach ($task['expected'] as $path => $after) {
        array_push($edits, ...nativeEdits($base[$path], $after));
    }
    $written = array_sum(array_map(static fn (array $e): int => strlen($e['old']) + strlen($e['new']), $edits));
    $dir = materialize($want);
    $rows[] = ['task' => $id, 'route' => 'native-ideal', 'applicable' => true] + judge($base, tree($dir), $task['expected'], $dir, $wantBehaviour)
        + ['read_bytes' => $readBytes, 'written_bytes' => $written, 'tool_calls' => ($task['needle'] === null ? 0 : 1) + count($readFiles) + count($edits), 'seconds' => null];
    remove($dir);

    // native-naive
    if ($task['naive'] !== null) {
        $naive = ($task['naive'])($base);
        $dir = materialize($naive);
        $changed = array_keys(array_diff_assoc($naive, $base));
        $rows[] = ['task' => $id, 'route' => 'native-naive', 'applicable' => true] + judge($base, tree($dir), $task['expected'], $dir, $wantBehaviour)
            + ['read_bytes' => $grepBytes, 'written_bytes' => strlen('->total()->grandTotal()') + strlen('public function total()public function grandTotal()'), 'tool_calls' => 1 + count($changed), 'seconds' => null];
        remove($dir);
    }

    // direct and map+edit run for real.
    foreach (['direct' => $task['direct'], 'map+edit' => $task['map']] as $route => $commands) {
        if ($commands === null) {
            $rows[] = ['task' => $id, 'route' => $route, 'applicable' => false];
            continue;
        }
        $dir = materialize($base);
        $started = microtime(true);
        $read = 0;
        $ok = true;
        foreach ($commands as $command) {
            [$code, $out] = run($dir, $command);
            $read += strlen($out);
            if ($code !== 0) {
                $ok = false;
                $planOutput = is_file($dir . '/plan.json') ? (string) file_get_contents($dir . '/plan.json') : '';
                fwrite(STDERR, "{$id}/{$route}: `{$command}` exited {$code}:\n{$out}{$planOutput}\n");
                break;
            }
        }
        $seconds = round(microtime(true) - $started, 2);
        if ($route === 'direct') {
            // The agent reads the one file it edits, then authors the plan; the apply result is read back.
            $authored = array_sum(array_map(static fn (string $c): int => strlen($c) - strlen($editBin) - 2, $commands));
            $read += array_sum(array_map(static fn (string $p): int => strlen($base[$p]), array_keys($task['expected'])));
            $calls = 1 + count($commands);
        } else {
            // The agent authors only the planner command; it reads the planner and apply output (the plan file is
            // machine evidence passed to agent-edit, not something the agent needs to read).
            $authored = strlen($commands[1]) - strlen($mapBin) - 2;
            $calls = count($commands);
        }
        $rows[] = ['task' => $id, 'route' => $route, 'applicable' => true] + ($ok ? judge($base, tree($dir), $task['expected'], $dir, $wantBehaviour) : ['correct' => false, 'unrelated_files' => 0, 'behaviour' => 'refused'])
            + ['read_bytes' => $read, 'written_bytes' => $authored, 'tool_calls' => $calls, 'seconds' => $seconds];
        remove($dir);
    }
}

if ($json) {
    echo json_encode(['schema_version' => '1.0', 'kind' => 'route_benchmark', 'rows' => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
} else {
    echo "| Task | Route | Correct | Unrelated files changed | Bytes read | Bytes authored | Tool calls | Seconds |\n";
    echo "| --- | --- | --- | ---: | ---: | ---: | ---: | ---: |\n";
    foreach ($rows as $row) {
        if (!$row['applicable']) {
            echo "| {$row['task']} | {$row['route']} | n/a | | | | | |\n";
            continue;
        }
        $verdict = $row['correct'] ? 'yes' : 'no (' . (str_starts_with($row['behaviour'], 'error:') ? 'runtime error' : $row['behaviour']) . ')';
        printf("| %s | %s | %s | %d | %d | %d | %d | %s |\n", $row['task'], $row['route'], $verdict, $row['unrelated_files'], $row['read_bytes'], $row['written_bytes'], $row['tool_calls'], $row['seconds'] === null ? '-' : (string) $row['seconds']);
    }
}

if ($check) {
    $by = [];
    foreach ($rows as $row) {
        $by[$row['task'] . '/' . $row['route']] = $row;
    }
    $expectations = [
        'rename/native-ideal' => true, 'rename/native-naive' => false, 'rename/map+edit' => true,
        'body/native-ideal' => true, 'body/direct' => true,
        'remove/native-ideal' => true, 'remove/direct' => true, 'remove/map+edit' => true,
    ];
    foreach ($expectations as $key => $correct) {
        if (($by[$key]['correct'] ?? null) !== $correct) {
            fwrite(STDERR, "route benchmark: expected {$key} correct=" . var_export($correct, true) . ', got ' . json_encode($by[$key] ?? null) . "\n");
            exit(1);
        }
    }
    fwrite(STDERR, "route benchmark: expectations hold\n");
}
