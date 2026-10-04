<?php

declare(strict_types=1);

/**
 * Proves the pipeline `agent-map plan -> agent-edit apply -> agent-edit verify` with no agent-loop present:
 * a private method move, applied and verified against a refreshed Map.
 */

$root = dirname(__DIR__);
$work = sys_get_temp_dir() . '/agent-edit-dogfood-' . bin2hex(random_bytes(6));
mkdir($work . '/src', 0o775, true);
file_put_contents($work . '/src/Source.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace Demo;\n\nfinal class Source\n{\n    private static function helper(int \$x): int\n    {\n        return \$x + 1;\n    }\n}\n");
file_put_contents($work . '/src/Target.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace Demo;\n\nfinal class Target\n{\n}\n");

$run = static function (string $command) use ($work): string {
    exec('cd ' . escapeshellarg($work) . ' && ' . $command . ' 2>&1', $lines, $code);
    if ($code !== 0) {
        fwrite(STDERR, $command . " failed ({$code}):\n" . implode("\n", $lines) . "\n");
        exit(1);
    }

    return implode("\n", $lines);
};

// Verification needs Git-observed changed_files evidence, so the fixture is a real repository.
$run('git init -q . && git add -A && git -c user.email=dogfood@example.invalid -c user.name=dogfood commit -qm init');

$map = escapeshellarg($root . '/vendor/bin/agent-map');
$edit = escapeshellarg($root . '/bin/agent-edit');
$build = $map . ' build --root=. --paths=src --out=.agent-map/php-symbols.json';

$run($build);
$run($map . ' method-move-plan ' . escapeshellarg('Demo\Source::helper') . ' ' . escapeshellarg('Demo\Target') . ' --index .agent-map/php-symbols.json --format json > plan.json');
$run($edit . ' apply plan.json --dry-run');
$run($edit . ' apply plan.json --task DOGFOOD');
if (!str_contains((string) file_get_contents($work . '/src/Target.php'), 'function helper')) {
    fwrite(STDERR, "Method was not moved.\n");
    exit(1);
}
$run($build);
echo $run($edit . ' verify --bundle=.agent-edit/receipts/DOGFOOD') . "\n";
