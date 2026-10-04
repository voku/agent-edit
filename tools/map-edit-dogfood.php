<?php

declare(strict_types=1);

/**
 * End-to-end proofs with only agent-map + agent-edit present (no agent-loop):
 *
 *   agent-map plan -> agent-edit apply --dry-run -> apply -> map rebuild -> agent-edit verify
 *
 * Usage: php tools/map-edit-dogfood.php [scenario ...]   (default: all)
 *
 * Negative scenarios prove the fail-closed side: a stale plan and a tampered plan are rejected with
 * every source file byte-identical afterwards.
 */

$root = dirname(__DIR__);
$map = escapeshellarg($root . '/vendor/bin/agent-map');
$edit = escapeshellarg($root . '/bin/agent-edit');

/** @return array{0: int, 1: string} */
function sh(string $dir, string $command): array
{
    $lines = [];
    exec('cd ' . escapeshellarg($dir) . ' && ' . $command . ' 2>&1', $lines, $code);

    return [$code, implode("\n", $lines)];
}

function must(string $dir, string $command): string
{
    [$code, $out] = sh($dir, $command);
    if ($code !== 0) {
        fwrite(STDERR, $command . " failed ({$code}):\n" . $out . "\n");
        exit(1);
    }

    return $out;
}

function expectFailure(string $dir, string $command, string $needle): void
{
    [$code, $out] = sh($dir, $command);
    if ($code === 0 || !str_contains($out, $needle)) {
        fwrite(STDERR, $command . " should have failed with '{$needle}' but exited {$code}:\n" . $out . "\n");
        exit(1);
    }
}

function assertContains(string $dir, string $file, string $needle): void
{
    if (!str_contains((string) file_get_contents($dir . '/' . $file), $needle)) {
        fwrite(STDERR, "{$file} does not contain '{$needle}'.\n");
        exit(1);
    }
}

/** @param array<string, string> $files */
function fixture(array $files): string
{
    $dir = sys_get_temp_dir() . '/agent-edit-dogfood-' . bin2hex(random_bytes(6));
    mkdir($dir, 0o775, true);
    foreach ($files as $path => $content) {
        if (!is_dir(dirname($dir . '/' . $path))) {
            mkdir(dirname($dir . '/' . $path), 0o775, true);
        }
        file_put_contents($dir . '/' . $path, $content);
    }
    // Verification needs Git-observed changed_files evidence, so every fixture is a real repository.
    must($dir, 'git init -q . && printf ".agent-map/\n.agent-edit/\nplan.json\n" > .gitignore && git add -A && git -c user.email=dogfood@example.invalid -c user.name=dogfood commit -qm init');

    return $dir;
}

/** @return array<string, string> */
function snapshot(string $dir): array
{
    $hashes = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir . '/src', FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        $hashes[substr($file->getPathname(), strlen($dir) + 1)] = (string) hash_file('sha256', $file->getPathname());
    }
    ksort($hashes);

    return $hashes;
}

function php(string $body, string $namespace = 'Demo'): string
{
    return "<?php\n\ndeclare(strict_types=1);\n\nnamespace {$namespace};\n\n" . $body . "\n";
}

$build = static fn (string $dir) => must($dir, $map . ' build --root=. --paths=src --out=.agent-map/php-symbols.json');

$composer = json_encode(['autoload' => ['psr-4' => ['App\\Legacy\\' => 'src/Legacy/', 'App\\Modern\\' => 'src/Modern/', 'App\\Client\\' => 'src/Client/']]], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

/**
 * @param callable(string): void $assert
 */
$happy = static function (string $name, array $files, string $planCommand, string $planType, callable $assert) use ($build, $map, $edit): void {
    $dir = fixture($files);
    $build($dir);
    must($dir, $map . ' ' . $planCommand . ' --index .agent-map/php-symbols.json --format json > plan.json');
    $plan = json_decode((string) file_get_contents($dir . '/plan.json'), true, 512, JSON_THROW_ON_ERROR);
    if (($plan['status'] ?? null) !== 'safe' || ($plan['type'] ?? null) !== $planType) {
        fwrite(STDERR, "{$name}: expected a safe {$planType}, got " . json_encode([$plan['type'] ?? null, $plan['status'] ?? null, $plan['blockers'] ?? null]) . "\n");
        exit(1);
    }

    $before = snapshot($dir);
    must($dir, $edit . ' apply plan.json --dry-run --task DRY');
    if (snapshot($dir) !== $before) {
        fwrite(STDERR, "{$name}: dry run changed source.\n");
        exit(1);
    }
    must($dir, $edit . ' apply plan.json --task REAL');
    $assert($dir);
    $build($dir);
    $out = must($dir, $edit . ' verify --bundle=.agent-edit/receipts/REAL');
    if (!str_contains($out, 'verification: passed')) {
        fwrite(STDERR, "{$name}: verify did not pass:\n{$out}\n");
        exit(1);
    }
    echo "ok   {$name} ({$planType}@1.0)\n";
};

$scenarios = [
    'method-rename' => static fn () => $happy(
        'method-rename',
        ['src/Service.php' => php("final class Service\n{\n    public function oldName(): int\n    {\n        return 1;\n    }\n}"), 'src/Caller.php' => php("final class Caller\n{\n    public function run(Service \$service): int\n    {\n        return \$service->oldName();\n    }\n}")],
        "rename-plan 'Demo\\Service::oldName' newName",
        'method_rename_plan',
        static function (string $dir): void {
            assertContains($dir, 'src/Service.php', 'function newName');
            assertContains($dir, 'src/Caller.php', '->newName()');
        },
    ),
    'class-rename' => static fn () => $happy(
        'class-rename',
        ['src/Service.php' => php("final class Service\n{\n}"), 'src/Caller.php' => php("final class Caller\n{\n    public function make(): Service\n    {\n        return new Service();\n    }\n}")],
        "class-rename-plan 'Demo\\Service' RenamedService",
        'class_rename_plan',
        static function (string $dir): void {
            if (!is_file($dir . '/src/RenamedService.php') || is_file($dir . '/src/Service.php')) {
                fwrite(STDERR, "class-rename: file move was not published.\n");
                exit(1);
            }
            assertContains($dir, 'src/Caller.php', 'new RenamedService()');
        },
    ),
    'class-move' => static fn () => $happy(
        'class-move',
        ['composer.json' => $composer, 'src/Legacy/Service.php' => php("final class Service\n{\n}", 'App\\Legacy'), 'src/Client/Caller.php' => php("use App\\Legacy\\Service;\n\nfinal class Caller\n{\n    public function make(): Service\n    {\n        return new Service();\n    }\n}", 'App\\Client')],
        "class-move-plan 'App\\Legacy\\Service' 'App\\Modern\\Service'",
        'class_move_plan',
        static function (string $dir): void {
            if (!is_file($dir . '/src/Modern/Service.php') || is_file($dir . '/src/Legacy/Service.php')) {
                fwrite(STDERR, "class-move: file move was not published.\n");
                exit(1);
            }
            assertContains($dir, 'src/Modern/Service.php', 'namespace App\\Modern;');
            assertContains($dir, 'src/Client/Caller.php', 'use App\\Modern\\Service;');
        },
    ),
    'method-move' => static fn () => $happy(
        'method-move',
        ['src/Source.php' => php("final class Source\n{\n    private static function helper(int \$x): int\n    {\n        return \$x + 1;\n    }\n}"), 'src/Target.php' => php("final class Target\n{\n}")],
        "method-move-plan 'Demo\\Source::helper' 'Demo\\Target'",
        'method_move_plan',
        static function (string $dir): void {
            assertContains($dir, 'src/Target.php', 'function helper');
        },
    ),
    // Both classes live in one file: the move re-inserts the removed method text into the same file.
    'method-move-same-file' => static fn () => $happy(
        'method-move-same-file',
        ['src/Both.php' => php("final class Source\n{\n    private static function helper(int \$x): int\n    {\n        return \$x + 1;\n    }\n}\n\nfinal class Target\n{\n}")],
        "method-move-plan 'Demo\\Source::helper' 'Demo\\Target'",
        'method_move_plan',
        static function (string $dir): void {
            $source = (string) file_get_contents($dir . '/src/Both.php');
            if (substr_count($source, 'function helper') !== 1 || strpos($source, 'function helper') < strpos($source, 'final class Target')) {
                fwrite(STDERR, "method-move-same-file: the method was not moved into Target.\n");
                exit(1);
            }
        },
    ),
    // Fail-closed: the source changed after planning, so the plan's SHA-256 evidence no longer matches.
    'stale-plan-rejected' => static function () use ($build, $map, $edit): void {
        $dir = fixture(['src/Service.php' => php("final class Service\n{\n    public function oldName(): int\n    {\n        return 1;\n    }\n}")]);
        $build($dir);
        must($dir, $map . " rename-plan 'Demo\\Service::oldName' newName --index .agent-map/php-symbols.json --format json > plan.json");
        file_put_contents($dir . '/src/Service.php', php("final class Service\n{\n    // edited after planning\n    public function oldName(): int\n    {\n        return 1;\n    }\n}"));
        $before = snapshot($dir);
        expectFailure($dir, $edit . ' apply plan.json --task STALE', 'stale');
        if (snapshot($dir) !== $before) {
            fwrite(STDERR, "stale-plan-rejected: source changed.\n");
            exit(1);
        }
        echo "ok   stale-plan-rejected\n";
    },
    // Fail-closed: a plan whose replacement text is tampered into invalid PHP fails the pre-publication syntax gate,
    // and neither of the two files it touches is written (all-or-nothing).
    'tampered-plan-writes-nothing' => static function () use ($build, $map, $edit): void {
        $dir = fixture(['src/Source.php' => php("final class Source\n{\n    private static function helper(int \$x): int\n    {\n        return \$x + 1;\n    }\n}"), 'src/Target.php' => php("final class Target\n{\n}")]);
        $build($dir);
        must($dir, $map . " method-move-plan 'Demo\\Source::helper' 'Demo\\Target' --index .agent-map/php-symbols.json --format json > plan.json");
        $plan = json_decode((string) file_get_contents($dir . '/plan.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach ($plan['edits'] as &$planEdit) {
            if ($planEdit['role'] === 'method_declaration_insertion') {
                $planEdit['replacement'] = "\n    private static function helper(int \$x): int {{{ broken\n";
            }
        }
        unset($planEdit);
        file_put_contents($dir . '/plan.json', json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $before = snapshot($dir);
        expectFailure($dir, $edit . ' apply plan.json --task TAMPER', 'syntax');
        if (snapshot($dir) !== $before) {
            fwrite(STDERR, "tampered-plan-writes-nothing: a source file changed.\n");
            exit(1);
        }
        if (glob($dir . '/src/*agent-edit-plan-*') !== []) {
            fwrite(STDERR, "tampered-plan-writes-nothing: temporary files left behind.\n");
            exit(1);
        }
        echo "ok   tampered-plan-writes-nothing\n";
    },
    // Real filesystem publication failure: Client/Caller.php is published first, then the class move into
    // a deliberately non-writable existing destination directory fails. The transaction must restore both files.
    'publication-rollback' => static function () use ($build, $map, $edit, $composer): void {
        $dir = fixture([
            'composer.json' => $composer,
            'src/Client/Caller.php' => php("use App\\Legacy\\Service;\n\nfinal class Caller\n{\n    public function make(): Service\n    {\n        return new Service();\n    }\n}", 'App\\Client'),
            'src/Legacy/Service.php' => php("final class Service\n{\n}", 'App\\Legacy'),
            'src/Modern/.keep' => "\n",
        ]);
        $build($dir);
        must($dir, $map . " class-move-plan 'App\\Legacy\\Service' 'App\\Modern\\Service' --index .agent-map/php-symbols.json --format json > plan.json");
        $before = snapshot($dir);

        $destinationDirectory = $dir . '/src/Modern';
        if (!chmod($destinationDirectory, 0o555)) {
            fwrite(STDERR, "publication-rollback: unable to make destination directory read-only.\n");
            exit(1);
        }
        clearstatcache(true, $destinationDirectory);
        if (is_writable($destinationDirectory)) {
            chmod($destinationDirectory, 0o775);
            fwrite(STDERR, "publication-rollback: environment still considers the read-only destination writable.\n");
            exit(1);
        }

        [$code, $out] = sh($dir, $edit . ' apply plan.json --task ROLLBACK');
        chmod($destinationDirectory, 0o775);
        if ($code === 0 || !str_contains($out, 'every source file was restored')) {
            fwrite(STDERR, "publication-rollback: expected rollback-success failure, got {$code}:\n{$out}\n");
            exit(1);
        }

        if (snapshot($dir) !== $before) {
            fwrite(STDERR, "publication-rollback: source snapshot changed after rollback.\n");
            exit(1);
        }
        if (!is_file($dir . '/src/Legacy/Service.php') || is_file($dir . '/src/Modern/Service.php')) {
            fwrite(STDERR, "publication-rollback: class move was not fully rolled back.\n");
            exit(1);
        }
        assertContains($dir, 'src/Client/Caller.php', 'use App\\Legacy\\Service;');

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir . '/src', FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (str_contains($file->getFilename(), '.agent-edit-plan-')) {
                fwrite(STDERR, "publication-rollback: temporary publication artifact remains: {$file->getPathname()}\n");
                exit(1);
            }
        }

        $receiptPath = $dir . '/.agent-edit/receipts/ROLLBACK/execution.json';
        $receipt = json_decode((string) file_get_contents($receiptPath), true, 512, JSON_THROW_ON_ERROR);
        if (
            ($receipt['status'] ?? null) !== 'runner_failed'
            || ($receipt['runner']['exit_code'] ?? null) !== 1
            || ($receipt['changed_files'] ?? null) !== []
            || ($receipt['changed_files_source'] ?? null) !== 'git_status_diff'
        ) {
            fwrite(STDERR, "publication-rollback: failure receipt does not prove a clean rollback: " . json_encode($receipt) . "\n");
            exit(1);
        }

        [$gitCode, $gitOut] = sh($dir, 'git status --porcelain -- src');
        if ($gitCode !== 0 || trim($gitOut) !== '') {
            fwrite(STDERR, "publication-rollback: Git still observes source changes after rollback:\n{$gitOut}\n");
            exit(1);
        }

        echo "ok   publication-rollback (real filesystem failure + runner_failed receipt)\n";
    },
];

$requested = array_slice($argv, 1);
foreach ($requested === [] ? array_keys($scenarios) : $requested as $name) {
    if (!isset($scenarios[$name])) {
        fwrite(STDERR, "Unknown scenario: {$name}\n");
        exit(2);
    }
    $scenarios[$name]();
}
