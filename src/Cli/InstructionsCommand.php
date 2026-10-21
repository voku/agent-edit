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
                'Two routes. A change that reaches other files (rename, move or remove a class, method, property or constant) needs caller evidence: use a deterministic agent-map plan. A quick local fix (a body edit, config or documentation text, a new file, a change to a private method) reaches no other file: author a source_edit_plan yourself, which needs no Map. agent-edit never chooses refactorings and never calls a model.',
                'Map route: build a fresh agent-map index for the task scope before planning. Check the installed Map plan capabilities and use only an exact plan_type and contract_version supported by both Map and agent-edit.',
                'The plans list is the exact Map/Edit intersection (plus the Map-free direct plan) when capability_resolution is map_intersection. With registry_only, it is only agent-edit’s upper bound; confirm each Map plan against the installed Map before using it.',
                'Generate a Map plan with its listed agent-map command; consult that command’s --help for exact target arguments. If requires_phpstan is true, use a PHPStan-backed Map. Inspect the full plan and stop if it is not safe, requires review, has blockers, blind spots, or stale evidence. Do not bypass a refusal or rewrite plan evidence by hand.',
                'Run agent-edit apply PLAN --dry-run first. Apply only if preflight accepts, then rebuild the same Map and run agent-edit verify against the returned receipt bundle.',
                'Direct route: read the file first and anchor on text you have just read. Run agent-edit edit FILE --find TEXT --replace TEXT (use --find-file/--replace-file for multi-line text), or agent-edit apply - with a source_edit_plan on stdin for several edits or new files; agent-edit schema prints the contract. Add --dry-run to validate without writing.',
                'Read a refusal and act on it: an ambiguous anchor lists its lines (add context, or set occurrence/all), a whitespace mismatch points at the real line, a changed file must be re-read, and an API change (removing, renaming or changing the signature of a class, function or non-private method) needs an agent-map plan, or allow_signature_change after you checked the callers yourself. Never retry the same refused edit unchanged.',
                'After a direct edit run agent-edit verify --bundle=BUNDLE. agent-edit undo --bundle=BUNDLE restores the pre-edit bytes, and refuses once a touched file changed again, so undo later edits first.',
                'Treat verification as proof of the edit’s structural postcondition, not behavioral correctness. Run the repository’s relevant tests and static analysis as well; resolve open residue and verify again.',
                'If neither route fits the requested operation (for example a behavior change spanning many files), use the host’s normal coding and review workflow for that change. Do not force it into agent-edit.',
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
