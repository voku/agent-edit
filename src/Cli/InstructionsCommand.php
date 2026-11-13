<?php

declare(strict_types=1);

namespace voku\AgentEdit\Cli;

use HelgeSverre\Toon\Toon;
use InvalidArgumentException;
use JsonException;
use voku\AgentEdit\Capability\CapabilityRegistry;
use voku\AgentEdit\Capability\PlanCapability;

/** Emits reusable, registry-backed guidance for coding agents integrating agent-edit. */
final readonly class InstructionsCommand
{
    public function __construct(private CapabilityRegistry $capabilities = new CapabilityRegistry())
    {
    }

    /** @param list<string> $tokens */
    public function run(array $tokens): int
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
            fwrite(STDERR, '[ERROR] Unknown instructions option: ' . $token . "\n");

            return 2;
        }

        try {
            if ($mapCapabilities === null) {
                $capabilityData = $this->capabilities->toArray();
                $plans = array_map(fn (PlanCapability $capability): array => $this->describeCapability($capability, false), $this->capabilities->all());
                $resolved = false;
            } else {
                $capabilityData = $this->capabilities->intersect($this->readMapCapabilities($mapCapabilities));
                $plans = array_map(function (array $entry): array {
                    $map = $entry['map'] ?? null;
                    if (!is_array($map)) {
                        throw new InvalidArgumentException('Map/Edit intersection contains malformed planner capability evidence.');
                    }

                    return $this->describePlan($map);
                }, $capabilityData['executable']);
                // Caller-authored plans need no Map, so the installed Map can neither enable nor disable them.
                foreach ($this->capabilities->all() as $capability) {
                    if (!$capability->requiresMap) {
                        $plans[] = $this->describeCapability($capability, true);
                    }
                }
                $resolved = true;
            }
        } catch (InvalidArgumentException|JsonException $exception) {
            fwrite(STDERR, '[ERROR] ' . $exception->getMessage() . "\n");

            return 2;
        }

        $payload = [
            'schema_version' => '1.0',
            'type' => 'agent_edit_coding_agent_instructions',
            'capability_resolution' => $resolved ? 'map_intersection' : 'registry_only',
            'instructions' => [
                'agent-edit accepts two explicit routes: a versioned agent-map plan for identity-changing refactors requiring caller evidence, or a caller-authored source_edit_plan for bounded local edits. Neither plan grants mutation approval.',
                'Map plans require matching plan_type and contract_version from the installed Map/Edit capability intersection (--with-map); registry_only is only an upper bound. Respect requires_phpstan and refuse blocked, review_required, stale or ambiguous evidence.',
                'Apply a Map plan only after agent-edit apply PLAN --dry-run accepts it; then refresh the Map and run agent-edit verify --bundle=BUNDLE. Never rewrite plan evidence to bypass a refusal.',
                'Direct edits use agent-edit edit FILE --find TEXT --replace TEXT or a source_edit_plan on stdin. Anchors must resolve unambiguously; PHP symbol operations are available in the schema. Public signature changes require explicit allow_signature_change only after the caller has checked affected consumers.',
                'Failures expose error.code and next_action; a refused plan must not be retried unchanged. agent-edit undo is digest-guarded and refuses to overwrite later source changes.',
                'Verification proves structural postconditions, not behavior or authorization. The host owns approval, tests, static analysis and review. Unsupported changes belong to the normal coding and review workflow, not an invented agent-edit capability.',
            ],
            'plans' => $plans,
            'capability_data' => $capabilityData,
        ];

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

    /**
     * @param array<string, mixed> $map
     * @return array<string, mixed>
     */
    private function describePlan(array $map): array
    {
        $planType = $map['plan_type'] ?? null;
        $command = $map['command'] ?? null;
        $version = $map['contract_version'] ?? null;
        $backend = $map['semantic_backend'] ?? null;
        if (!is_string($planType) || !is_string($command) || !preg_match('/^[a-z][a-z0-9-]*$/', $command)
            || !is_string($version) || !is_string($backend) || $backend === '') {
            throw new InvalidArgumentException('Map/Edit intersection lacks exact planner command evidence.');
        }
        $capability = $this->capabilities->find($planType);
        if ($capability === null) {
            throw new InvalidArgumentException('Map/Edit intersection contains an unknown agent-edit capability.');
        }

        return $this->describeCapability($capability, true, $command, $version, $backend);
    }

    /** @return array<string, mixed> */
    private function describeCapability(PlanCapability $capability, bool $resolved, ?string $command = null, ?string $version = null, ?string $backend = null): array
    {
        if (!$capability->requiresMap) {
            return [
                'plan_type' => $capability->planType,
                'contract_versions' => $capability->contractVersions,
                'agent_map_command' => null,
                'semantic_backend' => 'none',
                'resolution' => 'direct_no_map_required',
                'authoring' => 'caller',
                'commands' => ['agent-edit edit', 'agent-edit apply -', 'agent-edit schema', 'agent-edit undo'],
                'requires_phpstan' => false,
                'moves_files' => false,
            ];
        }

        return [
            'plan_type' => $capability->planType,
            'contract_versions' => $resolved ? [$version] : $capability->contractVersions,
            'agent_map_command' => 'agent-map ' . ($command ?? $capability->agentMapCommand()),
            'semantic_backend' => $backend ?? ($capability->requiresPhpStan ? 'phpstan' : 'none'),
            'resolution' => $resolved ? 'executable_for_installed_map' : 'must_match_installed_map',
            'requires_phpstan' => $capability->requiresPhpStan,
            'moves_files' => $capability->movesFiles,
        ];
    }
}
