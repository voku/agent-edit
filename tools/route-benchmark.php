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
 * Every route's result is produced for real (the native-ideal edits too, applied as an exact-replace tool applies them)
 * and checked against a hand-written expected tree and by executing the code. Per route it
 * reports whether the result is correct, how many files changed that the task did not touch, the bytes an agent has
 * to read and to write (author) to perform it, and the tool calls. A route that does not fit a task is "n/a": a Map
 * plan for a body change, or a direct edit for a cross-file rename, would be the wrong tool and bias the comparison.
 *
 * What this does NOT measure: model behaviour (tokens a real agent spends deciding, its mistakes, retries). The native
 * rows are simulated editors, the ideal one making no mistakes at all. The numbers are lower bounds for the mechanical
 * part of each route, not agent productivity. See README "Route benchmark".
 *
 * Usage: php tools/route-benchmark.php [--json] [--check] [--write] [--fixture=DIR]
 *   --check  exit 1 unless every measured metric equals tools/route-benchmark.expected.json and the README table
 *            matches it (CI runs this, so the published numbers cannot drift)
 *   --write  record the measured results as the new snapshot and README table (review the diff before committing)
 */

$root = dirname(__DIR__);
$mapBin = $root . '/vendor/bin/agent-map';
$editBin = $root . '/bin/agent-edit';
$json = in_array('--json', $argv, true);
// --fixture=DIR writes the untouched fixture tree, so the real-agent experiment (tools/route-experiment) starts from
// exactly the files this benchmark measures.
foreach ($argv as $argument) {
    if (str_starts_with($argument, '--fixture=')) {
        $target = substr($argument, strlen('--fixture='));
        foreach (fixture() as $path => $content) {
            @mkdir(dirname($target . '/' . $path), 0o775, true);
            file_put_contents($target . '/' . $path, $content);
        }
        exit(0);
    }
}
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
                $edits = [];
                foreach ($files as $path => $content) {
                    if (str_contains($content, '->total()')) {
                        $files[$path] = str_replace('->total()', '->grandTotal()', $content);
                        $edits[] = ['old' => '->total()', 'new' => '->grandTotal()'];
                    }
                }
                $files['src/Invoice.php'] = str_replace('public function total()', 'public function grandTotal()', $files['src/Invoice.php']);
                $edits[] = ['old' => 'public function total()', 'new' => 'public function grandTotal()'];

                return [$files, $edits];
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
 * The old/new pairs a careful native exact-string editor would write: one per changed hunk of a line-level LCS diff,
 * each `old` grown by whole lines of context until it occurs exactly once in the file. Hunks whose context would
 * overlap are written as one edit, since an exact-replace tool cannot apply overlapping replacements one after the
 * other. Lines keep their own line endings, so applying the edits reproduces the target byte for byte.
 *
 * @return list<array{old: string, new: string}>
 */
function nativeEdits(string $before, string $after): array
{
    $a = preg_split('/(?<=\n)/', $before, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $b = preg_split('/(?<=\n)/', $after, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $n = count($a);
    $m = count($b);
    $lcs = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
    for ($i = $n - 1; $i >= 0; --$i) {
        for ($j = $m - 1; $j >= 0; --$j) {
            $lcs[$i][$j] = $a[$i] === $b[$j] ? $lcs[$i + 1][$j + 1] + 1 : max($lcs[$i + 1][$j], $lcs[$i][$j + 1]);
        }
    }
    // Hunks as [from, to) in $a with their replacement lines from $b.
    $hunks = [];
    $i = 0;
    $j = 0;
    $open = null;
    while ($i < $n || $j < $m) {
        if ($i < $n && $j < $m && $a[$i] === $b[$j]) {
            if ($open !== null) {
                $hunks[] = $open;
                $open = null;
            }
            ++$i;
            ++$j;
            continue;
        }
        $open ??= ['from' => $i, 'to' => $i, 'new' => ''];
        if ($j < $m && ($i >= $n || $lcs[$i][$j + 1] >= $lcs[$i + 1][$j])) {
            $open['new'] .= $b[$j++];
        } else {
            $open['to'] = ++$i;
        }
    }
    if ($open !== null) {
        $hunks[] = $open;
    }

    // Grow each hunk's window until its old text is unique, then merge windows that touch.
    $windows = [];
    foreach ($hunks as $hunk) {
        [$from, $to] = [$hunk['from'], $hunk['to']];
        $grow = 0;
        while (($text = implode('', array_slice($a, $from, $to - $from))) === '' || substr_count($before, $text) !== 1) {
            if ($grow++ % 2 === 0 && $from > 0) {
                --$from;
            } elseif ($to < $n) {
                ++$to;
            } elseif ($from > 0) {
                --$from;
            } else {
                break;
            }
        }
        $last = array_key_last($windows);
        if ($last !== null && $from <= $windows[$last]['to']) {
            $windows[$last]['to'] = max($to, $windows[$last]['to']);
            $windows[$last]['hunks'][] = $hunk;
        } else {
            $windows[] = ['from' => $from, 'to' => $to, 'hunks' => [$hunk]];
        }
    }

    $edits = [];
    foreach ($windows as $window) {
        $old = implode('', array_slice($a, $window['from'], $window['to'] - $window['from']));
        $new = '';
        $k = $window['from'];
        foreach ($window['hunks'] as $hunk) {
            $new .= implode('', array_slice($a, $k, $hunk['from'] - $k)) . $hunk['new'];
            $k = $hunk['to'];
        }
        $new .= implode('', array_slice($a, $k, $window['to'] - $k));
        $edits[] = ['old' => $old, 'new' => $new];
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

/**
 * Applies exact-string edits the way a native editor tool does: `old` must occur exactly once in the current file.
 *
 * @param array<string, string> $files
 * @param array<string, list<array{old: string, new: string}>> $editsByFile
 * @return array<string, string>|string the edited files, or why an edit could not be applied
 */
function applyExactEdits(array $files, array $editsByFile): array|string
{
    foreach ($editsByFile as $path => $edits) {
        foreach ($edits as $edit) {
            $count = substr_count($files[$path], $edit['old']);
            if ($count !== 1) {
                return sprintf('%s: old text occurs %d times', $path, $count);
            }
            $files[$path] = str_replace($edit['old'], $edit['new'], $files[$path]);
        }
    }

    return $files;
}

/** @return array<string, int|bool|string|null> */
function metrics(int $discoveryCalls, int $readCalls, int $editCalls, int $editCallsBatched, int $bytesRead, int $bytesAuthored): array
{
    return [
        'discovery_calls' => $discoveryCalls,
        'read_calls' => $readCalls,
        'edit_calls' => $editCalls,
        'tool_calls' => $discoveryCalls + $readCalls + $editCalls,
        'tool_calls_batched' => $discoveryCalls + $readCalls + $editCallsBatched,
        'bytes_read' => $bytesRead,
        'bytes_authored' => $bytesAuthored,
    ];
}

$rows = [];
$base = fixture();
foreach (tasks($mapBin, $editBin) as $id => $task) {
    $want = array_replace($base, $task['expected']);
    $wantDir = materialize($want);
    $wantBehaviour = behaviour($wantDir);
    remove($wantDir);
    $judgeFiles = static function (array|string $files) use ($base, $task, $wantBehaviour): array {
        if (is_string($files)) {
            return ['correct' => false, 'unrelated_files' => 0, 'behaviour' => 'edit failed: ' . $files];
        }
        $dir = materialize($files);
        $verdict = judge($base, tree($dir), $task['expected'], $dir, $wantBehaviour);
        remove($dir);

        return $verdict;
    };

    // Every route starts from the same task statement, which names the target symbol. Discovery is what a route
    // needs beyond that to find the places to change: a grep for a native editor, building the map for Map + Edit.
    $grepFiles = $task['needle'] === null ? [] : array_keys(array_filter($base, static fn (string $c): bool => str_contains($c, $task['needle'])));
    $grepBytes = strlen(implode("\n", array_map(static fn (string $p): string => $p . ':' . substr_count($base[$p], (string) $task['needle']), $grepFiles)));
    $grepCalls = $task['needle'] === null ? 0 : 1;

    // native-ideal: reads every file the grep names (or the one file it edits), then applies the smallest unique
    // old/new pairs for real. Correct only if those edits, applied as an exact-replace tool applies them, produce
    // the expected tree. Tool output of the edit calls themselves is not counted.
    $inspected = $task['needle'] === null ? array_keys($task['expected']) : $grepFiles;
    $editsByFile = [];
    foreach ($task['expected'] as $path => $after) {
        $editsByFile[$path] = nativeEdits($base[$path], $after);
    }
    $editCount = array_sum(array_map('count', $editsByFile));
    $authored = 0;
    foreach ($editsByFile as $edits) {
        foreach ($edits as $edit) {
            $authored += strlen($edit['old']) + strlen($edit['new']);
        }
    }
    $rows[] = ['task' => $id, 'route' => 'native-ideal', 'applicable' => true] + $judgeFiles(applyExactEdits($base, $editsByFile))
        + metrics($grepCalls, count($inspected), $editCount, count($editsByFile), $grepBytes + array_sum(array_map(static fn (string $p): int => strlen($base[$p]), $inspected)), $authored) + ['seconds' => null];

    // native-naive: one replace-all per pattern in every file that contains it; each edited file is read first,
    // as native editors require.
    if ($task['naive'] !== null) {
        [$naiveFiles, $naiveEdits] = ($task['naive'])($base);
        $edited = array_keys(array_diff_assoc($naiveFiles, $base));
        $naiveAuthored = array_sum(array_map(static fn (array $e): int => strlen($e['old']) + strlen($e['new']), $naiveEdits));
        $rows[] = ['task' => $id, 'route' => 'native-naive', 'applicable' => true] + $judgeFiles($naiveFiles)
            + metrics($grepCalls, count($edited), count($naiveEdits), count($edited), $grepBytes + array_sum(array_map(static fn (string $p): int => strlen($base[$p]), $edited)), $naiveAuthored) + ['seconds' => null];
    }

    // direct and map+edit run for real.
    foreach (['direct' => $task['direct'], 'map+edit' => $task['map']] as $route => $commands) {
        if ($commands === null) {
            $rows[] = ['task' => $id, 'route' => $route, 'applicable' => false];
            continue;
        }
        $dir = materialize($base);
        $started = microtime(true);
        $outputs = [];
        $ok = true;
        foreach ($commands as $command) {
            [$code, $out] = run($dir, $command);
            $outputs[] = strlen($out);
            if ($code !== 0) {
                $ok = false;
                $planOutput = is_file($dir . '/plan.json') ? (string) file_get_contents($dir . '/plan.json') : '';
                fwrite(STDERR, "{$id}/{$route}: `{$command}` exited {$code}:\n{$out}{$planOutput}\n");
                break;
            }
        }
        $seconds = round(microtime(true) - $started, 2);
        $verdict = $ok ? judge($base, tree($dir), $task['expected'], $dir, $wantBehaviour) : ['correct' => false, 'unrelated_files' => 0, 'behaviour' => 'refused'];
        remove($dir);
        if ($route === 'direct') {
            // Reads the one file it edits, authors the plan, reads the apply result. No discovery: the symbol is named.
            $metrics = metrics(0, count($task['expected']), count($commands), count($commands),
                array_sum(array_map(static fn (string $p): int => strlen($base[$p]), array_keys($task['expected']))) + array_sum($outputs),
                array_sum(array_map(static fn (string $c): int => strlen($c) - strlen($editBin) - 2, $commands)));
        } else {
            // Discovery is the map build; the agent authors the planner command and reads the build, planner and
            // apply output (the plan file is evidence handed to agent-edit, not read by the agent).
            $metrics = metrics(1, 0, count($commands) - 1, count($commands) - 1, array_sum($outputs), strlen($commands[1]) - strlen($mapBin) - 2);
        }
        $rows[] = ['task' => $id, 'route' => $route, 'applicable' => true] + $verdict + $metrics + ['seconds' => $seconds];
    }
}

/** The README table, generated from the rows so the published numbers cannot drift from the measured ones. */
function table(array $rows, array $titles): string
{
    $lines = [
        '| Task | Route | Correct | Unrelated files changed | Discovery calls | Bytes read | Bytes authored | Tool calls (per edit / batched) |',
        '| --- | --- | --- | ---: | ---: | ---: | ---: | ---: |',
    ];
    $previous = null;
    foreach ($rows as $row) {
        if (!$row['applicable']) {
            continue;
        }
        $task = $row['task'] === $previous ? '' : $titles[$row['task']];
        $previous = $row['task'];
        $verdict = $row['correct'] ? 'yes' : 'no (' . (str_starts_with((string) $row['behaviour'], 'error:') ? 'runtime error' : $row['behaviour']) . ')';
        $lines[] = sprintf('| %s | %s | %s | %d | %d | %s | %s | %d / %d |', $task, $row['route'], $verdict, $row['unrelated_files'], $row['discovery_calls'],
            number_format($row['bytes_read']), number_format($row['bytes_authored']), $row['tool_calls'], $row['tool_calls_batched']);
    }

    return implode("\n", $lines) . "\n";
}

$titles = ['rename' => 'rename `Invoice::total()` (3 call sites, 3 files)', 'body' => 'change one method body', 'remove' => 'remove an unused private method'];
$snapshotPath = __DIR__ . '/route-benchmark.expected.json';
$readmePath = dirname(__DIR__) . '/README.md';
// The deterministic part of every row: everything except wall-clock time and the runtime error text.
$snapshot = array_map(static function (array $row): array {
    unset($row['seconds']);
    if (isset($row['behaviour'])) {
        $row['behaviour'] = str_starts_with((string) $row['behaviour'], 'error:') ? 'runtime error' : $row['behaviour'];
    }

    return $row;
}, $rows);
$rendered = table($rows, $titles);

if ($json) {
    echo json_encode(['schema_version' => '1.0', 'kind' => 'route_benchmark', 'rows' => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
} else {
    echo $rendered;
}

$begin = "<!-- route-benchmark:begin (generated by tools/route-benchmark.php --write) -->\n";
$end = "<!-- route-benchmark:end -->";
if (in_array('--write', $argv, true)) {
    file_put_contents($snapshotPath, json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    $readme = (string) file_get_contents($readmePath);
    $from = strpos($readme, $begin);
    $to = strpos($readme, $end);
    if ($from === false || $to === false) {
        fwrite(STDERR, "README.md has no route-benchmark markers.\n");
        exit(1);
    }
    file_put_contents($readmePath, substr($readme, 0, $from + strlen($begin)) . $rendered . substr($readme, $to));
    fwrite(STDERR, "route benchmark: snapshot and README table written\n");
}

if ($check) {
    $expected = json_decode((string) @file_get_contents($snapshotPath), true);
    if ($expected !== $snapshot) {
        fwrite(STDERR, "route benchmark: results differ from tools/route-benchmark.expected.json. Inspect the change; if it is intended, run with --write.\n"
            . 'measured: ' . json_encode($snapshot, JSON_UNESCAPED_SLASHES) . "\n");
        exit(1);
    }
    if (!str_contains((string) file_get_contents($readmePath), $begin . $rendered . $end)) {
        fwrite(STDERR, "route benchmark: the README table does not match the measured results; run with --write.\n");
        exit(1);
    }
    fwrite(STDERR, "route benchmark: results, snapshot and README table agree\n");
}
