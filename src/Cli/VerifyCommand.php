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
            $result = $this->engine->verify($this->projectRoot, $options['bundle'], $options['map_index'], $options['map_root']);
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
        echo $label . " verification: passed\n";
        echo '- bundle: ' . $bundle . "\n";
        if ($label === 'Refactor') {
            echo '- plan: ' . (string) ($plan['type'] ?? '') . '@' . (string) ($plan['contract_version'] ?? '') . "\n";
        }
        echo '- target: ' . (string) ($plan['target_id'] ?? '') . "\n";
        echo '- result: ' . $bundle . '/' . EditEngine::VERIFICATION_FILE . "\n";

        return 0;
    }

    /**
     * @param list<string> $tokens
     * @return array{bundle: string, map_index: ?string, map_root: string}
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
            if (!in_array($name, ['bundle', 'map-index', 'map-root'], true) || $value === '' || isset($values[$name])) {
                throw new RuntimeException('Invalid or duplicate refactor verify option: --' . $name);
            }
            $values[$name] = $value;
        }

        return [
            'bundle' => $values['bundle'] ?? '',
            'map_index' => $values['map-index'] ?? null,
            'map_root' => $values['map-root'] ?? '.',
        ];
    }

    private function help(): string
    {
        return <<<'TXT'
Usage:
  agent-edit verify --bundle=PATH [--map-index PATH] [--map-root PATH]

Read-only verification of one applied agent-edit receipt bundle (`execution.json`). It requires independently
observed changed-file evidence, binds the immutable plan, checks the refreshed Map and writes
verification-result.json into the bundle.

TXT;
    }
}
