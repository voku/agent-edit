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
    must($dir, 'git init -q . && printf ".agent-map/\n.agent-edit/\nplan.json\nplan.err\n" > .gitignore && git add -A && git -c user.email=dogfood@example.invalid -c user.name=dogfood commit -qm init');

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
    // Realistic multi-file rename: every statically resolved caller must follow the declaration.
    'method-rename-real-callers' => static fn () => $happy(
        'method-rename-real-callers',
        [
            'src/BillingService.php' => php(<<<'PHP'
final class BillingService
{
    /** Calculate tax in whole currency units. */
    public function calculateTax(int $amount): int
    {
        return intdiv($amount * 19, 100);
    }

    public function status(): string
    {
        return 'active';
    }
}
PHP),
            'src/Checkout.php' => php(<<<'PHP'
final class Checkout
{
    public function taxOn(int $amount): int
    {
        return (new BillingService())->calculateTax($amount);
    }
}
PHP),
            'src/Invoice.php' => php(<<<'PHP'
final class Invoice
{
    public function taxOn(int $amount): int
    {
        $service = new BillingService();

        return $service->calculateTax($amount);
    }
}
PHP),
        ],
        "rename-plan 'Demo\\BillingService::calculateTax' computeTax",
        'method_rename_plan',
        static function (string $dir): void {
            foreach (['src/BillingService.php', 'src/Checkout.php', 'src/Invoice.php'] as $path) {
                assertContains($dir, $path, 'computeTax');
                if (str_contains((string) file_get_contents($dir . '/' . $path), 'calculateTax')) {
                    fwrite(STDERR, "method-rename-real-callers: old method name remains in {$path}.\n");
                    exit(1);
                }
            }
            assertContains($dir, 'src/BillingService.php', 'function status(): string');
            assertContains($dir, 'src/BillingService.php', 'Calculate tax in whole currency units.');
            $run = <<<'PHP'
require 'src/BillingService.php';
require 'src/Checkout.php';
require 'src/Invoice.php';
echo (new \Demo\Checkout())->taxOn(100) + (new \Demo\Invoice())->taxOn(200);
PHP;
            if (must($dir, 'php -r ' . escapeshellarg($run)) !== '57') {
                fwrite(STDERR, "method-rename-real-callers: runtime behavior changed.\n");
                exit(1);
            }
        },
    ),
    // Relocate a pure, unused helper; keep neighboring methods and documentation unchanged.
    'method-move-real-docblock' => static fn () => $happy(
        'method-move-real-docblock',
        [
            'src/LegacyFormatter.php' => php(<<<'PHP'
final class LegacyFormatter
{
    /** Normalize a code without changing caller-visible state. */
    private static function normalizeCode(string $code): string
    {
        return strtoupper(trim($code));
    }

    public function keep(): string
    {
        return 'legacy';
    }
}
PHP),
            'src/CodeFormatter.php' => php(<<<'PHP'
final class CodeFormatter
{
    public function label(): string
    {
        return 'codes';
    }
}
PHP),
        ],
        "method-move-plan 'Demo\\LegacyFormatter::normalizeCode' 'Demo\\CodeFormatter'",
        'method_move_plan',
        static function (string $dir): void {
            assertContains($dir, 'src/LegacyFormatter.php', 'function keep(): string');
            assertContains($dir, 'src/CodeFormatter.php', 'function label(): string');
            assertContains($dir, 'src/CodeFormatter.php', 'Normalize a code without changing caller-visible state.');
            assertContains($dir, 'src/CodeFormatter.php', 'function normalizeCode(string $code): string');
            if (str_contains((string) file_get_contents($dir . '/src/LegacyFormatter.php'), 'function normalizeCode')) {
                fwrite(STDERR, "method-move-real-docblock: original declaration was not removed.\n");
                exit(1);
            }
            $run = <<<'PHP'
require 'src/LegacyFormatter.php';
require 'src/CodeFormatter.php';
$method = new \ReflectionMethod(\Demo\CodeFormatter::class, 'normalizeCode');
echo $method->invoke(null, ' ab-12 ')
    . ':' . (new \Demo\LegacyFormatter())->keep()
    . ':' . (new \Demo\CodeFormatter())->label();
PHP;
            if (must($dir, 'php -r ' . escapeshellarg($run)) !== 'AB-12:legacy:codes') {
                fwrite(STDERR, "method-move-real-docblock: moved method behaves differently.\n");
                exit(1);
            }
        },
    ),
    // PSR-4 relocation must update two independent consumers, not merely move one declaration.
    'class-move-multiple-consumers' => static fn () => $happy(
        'class-move-multiple-consumers',
        [
            'composer.json' => $composer,
            'src/Legacy/TaxRate.php' => php(<<<'PHP'
/** Tax calculation shared by checkout and invoicing. */
final class TaxRate
{
    public static function apply(int $amount): int
    {
        return $amount * 2;
    }
}
PHP, 'App\\Legacy'),
            'src/Client/Checkout.php' => php(<<<'PHP'
use App\Legacy\TaxRate;

final class Checkout
{
    public function total(): int
    {
        return TaxRate::apply(10);
    }
}
PHP, 'App\\Client'),
            'src/Client/Invoice.php' => php(<<<'PHP'
use App\Legacy\TaxRate;

final class Invoice
{
    public function total(): int
    {
        return TaxRate::apply(15);
    }
}
PHP, 'App\\Client'),
        ],
        "class-move-plan 'App\\Legacy\\TaxRate' 'App\\Modern\\TaxRate'",
        'class_move_plan',
        static function (string $dir): void {
            if (is_file($dir . '/src/Legacy/TaxRate.php') || !is_file($dir . '/src/Modern/TaxRate.php')) {
                fwrite(STDERR, "class-move-multiple-consumers: PSR-4 file move missing.\n");
                exit(1);
            }
            assertContains($dir, 'src/Modern/TaxRate.php', 'namespace App\\Modern;');
            assertContains($dir, 'src/Modern/TaxRate.php', 'Tax calculation shared by checkout and invoicing.');
            foreach (['src/Client/Checkout.php', 'src/Client/Invoice.php'] as $path) {
                assertContains($dir, $path, 'use App\\Modern\\TaxRate;');
                if (str_contains((string) file_get_contents($dir . '/' . $path), 'App\\Legacy\\TaxRate')) {
                    fwrite(STDERR, "class-move-multiple-consumers: stale consumer import {$path}.\n");
                    exit(1);
                }
            }
            $run = <<<'PHP'
require 'src/Modern/TaxRate.php';
require 'src/Client/Checkout.php';
require 'src/Client/Invoice.php';
echo (new \App\Client\Checkout())->total() + (new \App\Client\Invoice())->total();
PHP;
            if (must($dir, 'php -r ' . escapeshellarg($run)) !== '50') {
                fwrite(STDERR, "class-move-multiple-consumers: runtime behavior changed.\n");
                exit(1);
            }
        },
    ),
    // A caller changing after planning invalidates the whole multi-file rename, without partial edits.
    'rename-stale-caller' => static function () use ($build, $map, $edit): void {
        $dir = fixture([
            'src/Service.php' => php("final class Service\n{\n    public function legacy(): int\n    {\n        return 7;\n    }\n}"),
            'src/Caller.php' => php("final class Caller\n{\n    public function run(Service \$service): int\n    {\n        return \$service->legacy();\n    }\n}"),
        ]);
        $build($dir);
        [$planExit] = sh($dir, "(" . $map . " rename-plan 'Demo\\Service::legacy' modern --index .agent-map/php-symbols.json --format json > plan.json 2> plan.err)");
        if ($planExit !== 0) {
            fwrite(STDERR, "rename-stale-caller: baseline plan generation failed: " . (string) file_get_contents($dir . '/plan.err') . "\n");
            exit(1);
        }
        $plan = json_decode((string) file_get_contents($dir . '/plan.json'), true, 512, JSON_THROW_ON_ERROR);
        if (($plan['status'] ?? null) !== 'safe') {
            fwrite(STDERR, "rename-stale-caller: baseline plan is not safe.\n");
            exit(1);
        }
        file_put_contents($dir . '/src/Caller.php', (string) file_get_contents($dir . '/src/Caller.php') . "// edited since planning\n");
        $before = snapshot($dir);
        expectFailure($dir, $edit . ' apply plan.json --task STALE_CALLER', 'stale');
        if (snapshot($dir) !== $before) {
            fwrite(STDERR, "rename-stale-caller: an outdated plan changed source files.\n");
            exit(1);
        }
        assertContains($dir, 'src/Service.php', 'function legacy()');
        assertContains($dir, 'src/Caller.php', '->legacy()');
        echo "ok   rename-stale-caller (changed caller, no partial rename)\n";
    },
    // Moving a method that uses its owner's private state must be blocked, not change its meaning.
    'method-move-owner-dependent' => static function () use ($build, $map, $edit): void {
        $dir = fixture([
            'src/Owner.php' => php("final class Owner\n{\n    private static string \$prefix = 'old:';\n\n    private static function render(string \$suffix): string\n    {\n        return self::\$prefix . \$suffix;\n    }\n}"),
            'src/Other.php' => php("final class Other\n{\n}"),
        ]);
        $build($dir);
        [$planExit] = sh($dir, "(" . $map . " method-move-plan 'Demo\\Owner::render' 'Demo\\Other' --index .agent-map/php-symbols.json --format json > plan.json 2> plan.err)");
        $planOut = is_file($dir . '/plan.err') ? (string) file_get_contents($dir . '/plan.err') : '';
        if ($planExit === 0 || !is_file($dir . '/plan.json') || filesize($dir . '/plan.json') === 0) {
            fwrite(STDERR, "method-move-owner-dependent: blocked planner must return a nonzero status and JSON evidence: {$planOut}\n");
            exit(1);
        }
        $plan = json_decode((string) file_get_contents($dir . '/plan.json'), true, 512, JSON_THROW_ON_ERROR);
        if (($plan['status'] ?? null) !== 'blocked' || ($plan['blockers'] ?? []) === []) {
            fwrite(STDERR, "method-move-owner-dependent: unsafe owner dependency was not blocked.\n");
            exit(1);
        }
        $before = snapshot($dir);
        [$code, $out] = sh($dir, $edit . ' apply plan.json --dry-run --task OWNER_DEP');
        if ($code === 0 || snapshot($dir) !== $before) {
            fwrite(STDERR, "method-move-owner-dependent: unsafe plan passed or changed source: {$out}\n");
            exit(1);
        }
        echo "ok   method-move-owner-dependent (owner state, fail-closed)\n";
    },
    // A destination class already present at the PSR-4 path must not be overwritten by a class move.
    'class-move-target-collision' => static function () use ($build, $map, $edit, $composer): void {
        $dir = fixture([
            'composer.json' => $composer,
            'src/Legacy/Service.php' => php("final class Service\n{\n}", 'App\\Legacy'),
            'src/Modern/Service.php' => php("final class Service\n{\n}", 'App\\Modern'),
        ]);
        $build($dir);
        [$planExit] = sh($dir, "(" . $map . " class-move-plan 'App\\Legacy\\Service' 'App\\Modern\\Service' --index .agent-map/php-symbols.json --format json > plan.json 2> plan.err)");
        $planOut = is_file($dir . '/plan.err') ? (string) file_get_contents($dir . '/plan.err') : '';
        if ($planExit === 0 || !is_file($dir . '/plan.json') || filesize($dir . '/plan.json') === 0) {
            fwrite(STDERR, "class-move-target-collision: blocked planner must return a nonzero status and JSON evidence: {$planOut}\n");
            exit(1);
        }
        $plan = json_decode((string) file_get_contents($dir . '/plan.json'), true, 512, JSON_THROW_ON_ERROR);
        if (($plan['status'] ?? null) !== 'blocked' || ($plan['blockers'] ?? []) === []) {
            fwrite(STDERR, "class-move-target-collision: existing destination was not blocked.\n");
            exit(1);
        }
        $before = snapshot($dir);
        [$code, $out] = sh($dir, $edit . ' apply plan.json --dry-run --task COLLISION');
        if ($code === 0 || snapshot($dir) !== $before) {
            fwrite(STDERR, "class-move-target-collision: destructive plan passed or changed files: {$out}\n");
            exit(1);
        }
        assertContains($dir, 'src/Legacy/Service.php', 'namespace App\\Legacy;');
        assertContains($dir, 'src/Modern/Service.php', 'namespace App\\Modern;');
        echo "ok   class-move-target-collision (destination protected)\n";
    },
    'method-copy' => static fn () => $happy(
        'method-copy',
        ['src/Source.php' => php("final class Source\n{\n    public static function normalize(string \$value): string\n    {\n        return trim(\$value);\n    }\n}"), 'src/Destination.php' => php("final class Destination\n{\n}")],
        "method-copy-plan 'Demo\\Source::normalize' 'Demo\\Destination' --name=normalizeCopy",
        'method_copy_plan',
        static function (string $dir): void {
            assertContains($dir, 'src/Source.php', 'function normalize');
            assertContains($dir, 'src/Destination.php', 'function normalizeCopy');
        },
    ),
    // Released agent-map 0.22.6 must extract only importable class names from type expressions.
    // Older planners could emit invalid use statements from native nullable / PHPDoc generic types.
    'method-copy-composite-types' => static fn () => $happy(
        'method-copy-composite-types',
        [
            'src/Source.php' => php(<<<'PHP'
final class Source
{
    /**
     * @param int|null $value
     * @return array<string, mixed>
     */
    public static function wrap(?int $value): array
    {
        return ['value' => $value];
    }
}
PHP),
            'src/Destination.php' => php("final class Destination\n{\n}"),
        ],
        "method-copy-plan 'Demo\\Source::wrap' 'Demo\\Destination' --name=wrapCopy",
        'method_copy_plan',
        static function (string $dir): void {
            assertContains($dir, 'src/Source.php', 'function wrap(?int $value): array');
            assertContains($dir, 'src/Destination.php', 'function wrapCopy(?int $value): array');
            assertContains($dir, 'src/Destination.php', '@return array<string, mixed>');
            $destination = (string) file_get_contents($dir . '/src/Destination.php');
            foreach (['use int', 'use array', 'use mixed', 'use ?'] as $invalidImport) {
                if (str_contains($destination, $invalidImport)) {
                    fwrite(STDERR, "method-copy-composite-types: invalid import $invalidImport\n");
                    exit(1);
                }
            }
        },
    ),
    'method-scaffold' => static fn () => $happy(
        'method-scaffold',
        ['src/Destination.php' => php("final class Destination\n{\n}")],
        "method-scaffold-plan 'Demo\\Destination::parse' --param='string \$value' --return=string --doc='Parse a value.'",
        'method_scaffold_plan',
        static function (string $dir): void {
            assertContains($dir, 'src/Destination.php', 'function parse(string $value): string');
            assertContains($dir, 'src/Destination.php', '* Parse a value.');
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
