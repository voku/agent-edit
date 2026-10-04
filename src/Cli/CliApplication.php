<?php

declare(strict_types=1);

namespace voku\AgentEdit\Cli;

use HelgeSverre\Toon\Toon;
use InvalidArgumentException;
use JsonException;
use voku\AgentEdit\Capability\CapabilityRegistry;


/** Thin CLI over the package API: `apply`, `verify` and `capabilities`. */
final readonly class CliApplication
{
    public function __construct(
        private string $projectRoot,
        private CapabilityRegistry $capabilities = new CapabilityRegistry(),
    ) {
    }

    /** @param list<string> $argv */
    public function run(array $argv): int
    {
        $tokens = array_slice($argv, 1);
        $command = $tokens[0] ?? 'help';
        $rest = array_slice($tokens, 1);

        return match ($command) {
            'apply' => (new ApplyCommand($this->projectRoot))->run($rest),
            'verify' => (new VerifyCommand($this->projectRoot))->run($rest),
            'capabilities' => $this->capabilitiesCommand($rest),
            'help', '--help', '-h' => $this->help(),
            default => $this->unknown($command),
        };
    }

    /** @param list<string> $tokens */
    private function capabilitiesCommand(array $tokens): int
    {
        $format = 'json';
        $mapCapabilities = null;
        foreach ($tokens as $token) {
            if (str_starts_with($token, '--format=')) {
                $format = substr($token, strlen('--format='));
                continue;
            }
            if (str_starts_with($token, '--with-map=')) {
                $mapCapabilities = substr($token, strlen('--with-map='));
                continue;
            }
            fwrite(STDERR, '[ERROR] Unknown capabilities option: ' . $token . "\n");

            return 2;
        }

        try {
            $payload = $mapCapabilities === null
                ? $this->capabilities->toArray()
                : $this->capabilities->intersect($this->readMapCapabilities($mapCapabilities));
        } catch (InvalidArgumentException|JsonException $exception) {
            fwrite(STDERR, '[ERROR] ' . $exception->getMessage() . "\n");

            return 2;
        }
        if ($format === 'toon') {
            echo Toon::encode($payload) . "\n";

            return 0;
        }
        if ($format !== 'json') {
            fwrite(STDERR, "[ERROR] --format must be json or toon.\n");

            return 2;
        }
        echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";

        return 0;
    }

    /** @return array<mixed> */
    private function readMapCapabilities(string $source): array
    {
        $raw = $source === '-' ? stream_get_contents(STDIN) : @file_get_contents($source);
        if (!is_string($raw)) {
            throw new InvalidArgumentException('Unable to read Map capabilities from ' . $source . '.');
        }
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new InvalidArgumentException('Map capabilities must decode to an object.');
        }

        return $decoded;
    }

    private function unknown(string $command): int
    {
        fwrite(STDERR, '[ERROR] Unknown command: ' . $command . "\n");
        $this->help();

        return 2;
    }

    private function help(): int
    {
        echo <<<'TXT'
agent-edit - deterministic, evidence-backed source mutation for coding agents.

Usage:
  agent-edit apply PLAN [--dry-run] [--map-index PATH] [--map-root PATH] [--output-dir PATH] [--task LABEL]
  agent-edit verify --bundle=PATH [--map-index PATH] [--map-root PATH]
  agent-edit capabilities [--format=json|toon] [--with-map=FILE|-]

`--with-map` takes `agent-map plan-capabilities --format=json` and reports which plans are really executable.

Plans come from `agent-map` (for example `agent-map method-move-plan`). agent-edit never invents edits,
never calls a model, and never decides whether a task may mutate: that authority stays with the caller.

TXT;

        return 0;
    }
}
