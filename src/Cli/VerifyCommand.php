<?php

declare(strict_types=1);

namespace voku\AgentEdit\Cli;

use RuntimeException;
use Throwable;
use voku\AgentEdit\EditEngine;

/** CLI adapter over `EditEngine::verify()`. */
final readonly class VerifyCommand
{
    public function __construct(
        private string $projectRoot,
        private EditEngine $engine = new EditEngine(),
    ) {
    }

    /** @param list<string> $tokens */
    public function run(array $tokens): int
    {
        if (in_array($tokens[0] ?? '', ['help', '--help', '-h'], true)) {
            echo $this->help();

            return 0;
        }

        try {
            $options = $this->options($tokens);
            $result = $this->engine->verify($this->projectRoot, $options['bundle'], $options['map_index'], $options['map_root'], $options['accept_residue']);
        } catch (Throwable $exception) {
            fwrite(STDERR, '[ERROR] ' . $exception->getMessage() . "\n");

            return 2;
        }

        $plan = is_array($result['plan'] ?? null) ? $result['plan'] : [];
        $runner = is_string($plan['type'] ?? null) ? $this->engine->capabilities()->find($plan['type'])?->runner : null;
        $label = is_string($runner) && str_ends_with($runner, '-removal-plan')
            ? ucfirst(substr($runner, 0, -strlen('-removal-plan'))) . ' removal'
            : 'Refactor';
        $candidate = str_starts_with($options['bundle'], '/') ? $options['bundle'] : $this->projectRoot . '/' . $options['bundle'];
        $bundle = str_replace('\\', '/', (string) realpath($candidate));
        $status = is_string($result['status'] ?? null) ? $result['status'] : 'passed';
        $residue = is_array($result['residue'] ?? null) ? $result['residue'] : [];
        $imports = is_array($result['import_residue'] ?? null) ? $result['import_residue'] : [];
        $note = '';
        if ($status === 'incomplete') {
            if (($residue['status'] ?? null) === 'open') {
                $note = ' (residue_open: ' . (int) ($residue['open'] ?? 0) . ' non-historical Markdown/template mention(s) of the old symbol remain)';
            } elseif (($imports['status'] ?? null) === 'open') {
                $note = ' (import_residue_open: ' . (int) ($imports['open'] ?? 0) . ' `use` import(s) orphaned by the deletion remain)';
            } else {
                $note = ' (scope_unproven: only Map-indexed files were observed)';
            }
        }
        echo $label . ' verification: ' . $status . $note . "\n";
        echo '- bundle: ' . $bundle . "\n";
        if ($label === 'Refactor') {
            echo '- plan: ' . (string) ($plan['type'] ?? '') . '@' . (string) ($plan['contract_version'] ?? '') . "\n";
        }
        echo '- target: ' . (string) ($plan['target_id'] ?? '') . "\n";
        if ($residue !== []) {
            echo '- residue: ' . (string) ($residue['status'] ?? '') . ' (open ' . (int) ($residue['open'] ?? 0) . ', historical ' . (int) ($residue['historical'] ?? 0) . ")\n";
            foreach (array_slice(is_array($residue['references'] ?? null) ? $residue['references'] : [], 0, 10) as $reference) {
                if (is_array($reference)) {
                    echo '  - ' . (string) ($reference['path'] ?? '') . ':' . (int) ($reference['line'] ?? 0) . ' [' . (string) ($reference['confidence'] ?? '') . '] ' . (string) ($reference['matched'] ?? '') . "\n";
                }
            }
        }
        if ($imports !== []) {
            echo '- import residue: ' . (string) ($imports['status'] ?? '') . ' (open ' . (int) ($imports['open'] ?? 0) . ")\n";
            foreach (is_array($imports['imports'] ?? null) ? $imports['imports'] : [] as $import) {
                if (is_array($import)) {
                    echo '  - ' . (string) ($import['path'] ?? '') . ':' . (int) ($import['line'] ?? 0) . ' use ' . (string) ($import['import'] ?? '') . ";\n";
                }
            }
        }
        echo '- result: ' . $bundle . '/' . EditEngine::VERIFICATION_FILE . "\n";

        return $status === 'passed' ? 0 : 3;
    }

    /**
     * @param list<string> $tokens
     * @return array{bundle: string, map_index: ?string, map_root: string, accept_residue: ?string}
     */
    private function options(array $tokens): array
    {
        /** @var array<string, string> $values */
        $values = [];
        for ($index = 0, $count = count($tokens); $index < $count; ++$index) {
            $token = $tokens[$index];
            if (!str_starts_with($token, '--')) {
                throw new RuntimeException('Unexpected refactor verify argument: ' . $token);
            }
            $raw = substr($token, 2);
            if (str_contains($raw, '=')) {
                [$name, $value] = explode('=', $raw, 2);
            } else {
                $name = $raw;
                $value = $tokens[$index + 1] ?? null;
                if (!is_string($value) || str_starts_with($value, '--')) {
                    throw new RuntimeException('Missing value for refactor verify option: --' . $name);
                }
                ++$index;
            }
            if (!in_array($name, ['bundle', 'map-index', 'map-root', 'accept-residue'], true) || $value === '' || isset($values[$name])) {
                throw new RuntimeException('Invalid or duplicate refactor verify option: --' . $name);
            }
            $values[$name] = $value;
        }

        return [
            'bundle' => $values['bundle'] ?? '',
            'map_index' => $values['map-index'] ?? null,
            'map_root' => $values['map-root'] ?? '.',
            'accept_residue' => $values['accept-residue'] ?? null,
        ];
    }

    private function help(): string
    {
        return <<<'TXT'
Usage:
  agent-edit verify --bundle=PATH [--map-index PATH] [--map-root PATH] [--accept-residue=REASON]

Read-only verification of one applied agent-edit receipt bundle (`execution.json`). It requires independently
observed changed-file evidence, binds the immutable plan, checks the refreshed Map and writes
verification-result.json into the bundle.

For method rename, class rename and method removal plans it also re-scans Markdown and Twig/Smarty/Blade files for the old
symbol. Non-historical mentions that remain make an otherwise passed result `incomplete` (exit 3) until they are fixed, or
accepted with a recorded reason via --accept-residue=REASON.

TXT;
    }
}
