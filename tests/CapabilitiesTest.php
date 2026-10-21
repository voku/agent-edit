<?php

declare(strict_types=1);

namespace voku\AgentEdit\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use voku\AgentEdit\Capability\CapabilityRegistry;
use voku\AgentEdit\Cli\CliApplication;
use voku\AgentEdit\EditEngine;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\AnalysisFingerprint;

final class CapabilitiesTest extends TestCase
{
    public function testRegistryPublishesEveryApplyRoute(): void
    {
        $types = array_map(static fn ($capability): string => $capability->planType, (new CapabilityRegistry())->all());

        self::assertSame([
            'method_rename_plan',
            'function_rename_plan',
            'class_rename_plan',
            'property_rename_plan',
            'class_constant_rename_plan',
            'parameter_rename_plan',
            'class_move_plan',
            'method_move_plan',
            'method_copy_plan',
            'method_scaffold_plan',
            'method_removal_plan',
            'property_removal_plan',
            'class_constant_removal_plan',
            'class_removal_plan',
            'source_edit_plan',
        ], $types);
    }

    public function testIntersectionSeparatesExecutableFromPlannedOnly(): void
    {
        $result = (new CapabilityRegistry())->intersect([
            'type' => 'plan_capabilities',
            'capabilities' => [
                ['plan_type' => 'method_move_plan', 'contract_version' => '1.0'],
                ['plan_type' => 'method_copy_plan', 'contract_version' => '1.0'],
                ['plan_type' => 'class_move_plan', 'contract_version' => '2.0'],
            ],
        ]);

        self::assertSame(['method_move_plan', 'method_copy_plan'], array_map(static fn (array $row): string => $row['map']['plan_type'], $result['executable']));
        self::assertSame(['class_move_plan'], array_map(static fn (array $row): string => $row['plan_type'], $result['planned_not_executable']));
        self::assertContains('method_removal_plan', $result['executable_not_planned']);
        self::assertNotContains('method_move_plan', $result['executable_not_planned']);
    }

    public function testIntersectionRejectsForeignDocuments(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new CapabilityRegistry())->intersect(['type' => 'something_else']);
    }

    public function testCliPrintsCapabilitiesJson(): void
    {
        ob_start();
        $exit = (new CliApplication(sys_get_temp_dir()))->run(['agent-edit', 'capabilities', '--format=json']);
        $output = (string) ob_get_clean();

        self::assertSame(0, $exit);
        $decoded = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('method_move_plan', $decoded['capabilities'][7]['plan_type']);
        self::assertTrue($decoded['capabilities'][7]['transactional']);
    }

    public function testCliPrintsRegistryBackedCodingAgentInstructions(): void
    {
        ob_start();
        $exit = (new CliApplication(sys_get_temp_dir()))->run(['agent-edit', 'instructions', '--format=json']);
        $output = (string) ob_get_clean();

        self::assertSame(0, $exit);
        $decoded = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('agent_edit_coding_agent_instructions', $decoded['type']);
        self::assertSame('registry_only', $decoded['capability_resolution']);
        self::assertCount(15, $decoded['plans']);
        self::assertSame('agent-map rename-plan', $decoded['plans'][0]['agent_map_command']);
        self::assertSame('agent-map function-rename-plan', $decoded['plans'][1]['agent_map_command']);
        self::assertSame('must_match_installed_map', $decoded['plans'][0]['resolution']);
        self::assertStringContainsString('normal coding and review workflow', implode(' ', $decoded['instructions']));
    }

    public function testInstructionsWithMapExposeOnlyExactExecutableIntersection(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'agent-edit-map-capabilities-');
        self::assertNotFalse($path);
        file_put_contents($path, json_encode([
            'type' => 'plan_capabilities',
            'capabilities' => [
                ['plan_type' => 'method_move_plan', 'contract_version' => '1.0', 'command' => 'method-move-plan', 'semantic_backend' => 'phpstan'],
                ['plan_type' => 'method_move_plan', 'contract_version' => '2.0', 'command' => 'method-move-plan', 'semantic_backend' => 'phpstan'],
                ['plan_type' => 'method_copy_plan', 'contract_version' => '1.0', 'command' => 'method-copy-plan', 'semantic_backend' => 'phpstan'],
            ],
        ], JSON_THROW_ON_ERROR));

        try {
            ob_start();
            $exit = (new CliApplication(sys_get_temp_dir()))->run(['agent-edit', 'instructions', '--with-map=' . $path]);
            $output = (string) ob_get_clean();
        } finally {
            unlink($path);
        }

        self::assertSame(0, $exit);
        $decoded = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('map_intersection', $decoded['capability_resolution']);
        self::assertSame(['method_move_plan', 'method_copy_plan', 'source_edit_plan'], array_column($decoded['plans'], 'plan_type'), 'Map plans come from the installed Map; the Map-free direct plan is always available.');
        self::assertSame('agent-map method-copy-plan', $decoded['plans'][1]['agent_map_command']);
        self::assertSame('phpstan', $decoded['plans'][1]['semantic_backend']);
        self::assertSame('executable_for_installed_map', $decoded['plans'][0]['resolution']);
        self::assertSame(['method_move_plan'], array_column($decoded['capability_data']['planned_not_executable'], 'plan_type'));
        self::assertSame(['2.0'], array_column($decoded['capability_data']['planned_not_executable'], 'contract_version'));
    }

    public function testEngineRejectsUnsupportedPlanTypeBeforeTouchingSource(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new EditEngine())->preflight(
            ['type' => 'method_copy_plan'],
            new AgentMapIndex('1', sys_get_temp_dir(), 'x', [], [], [], new AnalysisFingerprint('', '', '', '')),
            sys_get_temp_dir(),
        );
    }

    public function testFutureContractVersionAndUnknownTypeStayFailClosed(): void
    {
        $result = (new CapabilityRegistry())->intersect([
            'type' => 'plan_capabilities',
            'capabilities' => [
                ['plan_type' => 'method_move_plan', 'contract_version' => '1.0'],
                ['plan_type' => 'method_move_plan', 'contract_version' => '1.1'],
                ['plan_type' => 'method_move_plan', 'contract_version' => '2.0'],
                ['plan_type' => 'interface_extract_plan', 'contract_version' => '1.0'],
            ],
        ]);

        // Map publishing method_move_plan@2.0 or a type agent-edit never heard of never becomes executable.
        self::assertCount(1, $result['executable']);
        self::assertSame(['1.1', '2.0', '1.0'], array_map(static fn (array $row): string => $row['contract_version'], $result['planned_not_executable']));
        self::assertSame(['interface_extract_plan'], array_values(array_map(static fn (array $row): string => $row['plan_type'], array_filter($result['planned_not_executable'], static fn (array $row): bool => $row['plan_type'] === 'interface_extract_plan'))));
    }

    public function testEngineRejectsFutureContractVersionBeforeReadingSource(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported plan type or contract version');

        (new EditEngine())->preflight(
            ['type' => 'method_move_plan', 'contract_version' => '2.0'],
            new AgentMapIndex('1', sys_get_temp_dir(), 'x', [], [], [], new AnalysisFingerprint('', '', '', '')),
            sys_get_temp_dir(),
        );
    }

    public function testLiveIntersectionWithInstalledAgentMapCoversEveryEditCapability(): void
    {
        $binary = dirname(__DIR__) . '/vendor/bin/agent-map';
        $raw = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($binary) . ' plan-capabilities --format=json');
        $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        $result = (new CapabilityRegistry())->intersect($payload);

        self::assertSame([], $result['executable_not_planned'], 'agent-edit executes a contract the installed agent-map no longer plans.');
        self::assertCount(14, $result['executable']);
        $plannedOnly = array_map(static fn (array $row): string => $row['plan_type'], $result['planned_not_executable']);
        self::assertSame(['class_scaffold_plan'], $plannedOnly, 'Every planned mutation contract except file creation must be executable through agent-edit.');
    }

    public function testRegistryIsTheOnlyPlanTypeListOutsideWireDecoders(): void
    {
        $offenders = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__) . '/src', \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $path = str_replace('\\', '/', $file->getPathname());
            if (!str_ends_with($path, '.php') || str_contains($path, '/src/Plan/') || str_contains($path, '/src/Verify/') || str_ends_with($path, '/Capability/CapabilityRegistry.php')) {
                continue;
            }
            if (preg_match("/'[a-z_]+_plan'/", (string) file_get_contents($path)) === 1) {
                $offenders[] = $path;
            }
        }

        self::assertSame([], $offenders, 'Plan-type literals must live in the registry, a wire decoder or a family verifier receipt binding only.');
    }

    public function testEveryCapabilityRoutesToARealApplierAndVerifier(): void
    {
        foreach ((new CapabilityRegistry())->all() as $capability) {
            $applierContract = $capability->requiresMap ? \voku\AgentEdit\Apply\PlanApplier::class : \voku\AgentEdit\Apply\MaplessPlanApplier::class;
            $verifierContract = $capability->requiresMap ? \voku\AgentEdit\Verify\BundleVerifier::class : \voku\AgentEdit\Verify\MaplessBundleVerifier::class;
            self::assertTrue(is_subclass_of($capability->applier, $applierContract), $capability->planType);
            self::assertTrue(is_subclass_of($capability->verifier, $verifierContract), $capability->planType);
            // Verification dispatch routes by runner name, so every capability sharing a runner must share its verifier.
            $routed = (new CapabilityRegistry())->findByRunner($capability->runner);
            self::assertNotNull($routed, $capability->planType);
            self::assertSame($capability->verifier, $routed->verifier, $capability->planType);
        }
    }

    public function testTypePlannedOnlyAtAnUnsupportedVersionStillCountsAsExecutableNotPlanned(): void
    {
        $result = (new CapabilityRegistry())->intersect([
            'type' => 'plan_capabilities',
            'capabilities' => [['plan_type' => 'class_move_plan', 'contract_version' => '2.0']],
        ]);

        self::assertSame([], $result['executable']);
        self::assertContains('class_move_plan', $result['executable_not_planned']);
    }

    public function testDirectEditIsReportedAsDirectNeverAsUnplannedAndNeedsNoMap(): void
    {
        $result = (new CapabilityRegistry())->intersect([
            'type' => 'plan_capabilities',
            'capabilities' => [['plan_type' => 'method_move_plan', 'contract_version' => '1.0']],
        ]);

        self::assertNotContains('source_edit_plan', $result['executable_not_planned']);
        self::assertSame(['source_edit_plan'], array_map(static fn (array $row): string => $row['plan_type'], $result['direct']));
        self::assertFalse($result['direct'][0]['requires_map']);
        self::assertTrue($result['direct'][0]['transactional']);

        $own = (new CapabilityRegistry())->find('method_move_plan');
        self::assertNotNull($own);
        self::assertTrue($own->toArray()['requires_map']);
    }

    public function testSourceEditPlanIsNotExecutableEvenIfAMapEverPublishedTheSameName(): void
    {
        // A planner publishing the type does not turn it into a Map-backed contract: it stays direct.
        $result = (new CapabilityRegistry())->intersect([
            'type' => 'plan_capabilities',
            'capabilities' => [['plan_type' => 'source_edit_plan', 'contract_version' => '1.0']],
        ]);

        self::assertSame([], $result['executable']);
        self::assertSame([], $result['planned_not_executable']);
        self::assertSame(['source_edit_plan'], array_map(static fn (array $row): string => $row['plan_type'], $result['direct']));
    }

    public function testInstructionsDescribeTheDirectRouteWithoutInventingAMapCommand(): void
    {
        ob_start();
        $exit = (new CliApplication(sys_get_temp_dir()))->run(['agent-edit', 'instructions', '--format=json']);
        $decoded = json_decode((string) ob_get_clean(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(0, $exit);
        $direct = array_values(array_filter($decoded['plans'], static fn (array $plan): bool => $plan['plan_type'] === 'source_edit_plan'));
        self::assertCount(1, $direct);
        self::assertNull($direct[0]['agent_map_command'], 'There is no `agent-map source-edit-plan`.');
        self::assertSame('direct_no_map_required', $direct[0]['resolution']);
        self::assertSame('caller', $direct[0]['authoring']);
        self::assertSame('none', $direct[0]['semantic_backend']);
        self::assertContains('agent-edit edit', $direct[0]['commands']);

        foreach (array_diff_key($decoded['plans'], [array_key_last($decoded['plans']) => 1]) as $plan) {
            self::assertStringStartsWith('agent-map ', (string) $plan['agent_map_command'], $plan['plan_type']);
        }
        $text = implode("\n", $decoded['instructions']);
        foreach (['source_edit_plan', 'agent-edit edit FILE', 'agent-edit schema', 'allow_signature_change', 'agent-edit undo', 'normal coding and review workflow'] as $needle) {
            self::assertStringContainsString($needle, $text);
        }
        self::assertStringNotContainsString('does not author arbitrary source edits', $text, 'The old "no direct edits" guidance contradicts the direct route.');
    }

    public function testInstructionsWithAnEmptyMapStillOfferTheDirectRoute(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'agent-edit-map-capabilities-');
        self::assertNotFalse($path);
        file_put_contents($path, json_encode(['type' => 'plan_capabilities', 'capabilities' => []], JSON_THROW_ON_ERROR));
        try {
            ob_start();
            $exit = (new CliApplication(sys_get_temp_dir()))->run(['agent-edit', 'instructions', '--with-map=' . $path]);
            $decoded = json_decode((string) ob_get_clean(), true, 512, JSON_THROW_ON_ERROR);
        } finally {
            unlink($path);
        }

        self::assertSame(0, $exit);
        self::assertSame(['source_edit_plan'], array_column($decoded['plans'], 'plan_type'));
        self::assertSame('map_intersection', $decoded['capability_resolution']);
    }

    public function testMapPayloadCannotDuplicateTheDirectPlanInInstructions(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'agent-edit-map-capabilities-');
        self::assertNotFalse($path);
        file_put_contents($path, json_encode([
            'type' => 'plan_capabilities',
            'capabilities' => [[
                'plan_type' => 'source_edit_plan',
                'contract_version' => '1.0',
                'command' => 'source-edit-plan',
                'semantic_backend' => 'none',
            ]],
        ], JSON_THROW_ON_ERROR));
        try {
            ob_start();
            $exit = (new CliApplication(sys_get_temp_dir()))->run(['agent-edit', 'instructions', '--with-map=' . $path]);
            $decoded = json_decode((string) ob_get_clean(), true, 512, JSON_THROW_ON_ERROR);
        } finally {
            unlink($path);
        }

        self::assertSame(0, $exit);
        self::assertSame(['source_edit_plan'], array_column($decoded['plans'], 'plan_type'));
        self::assertSame([], $decoded['capability_data']['executable']);
        self::assertSame(['source_edit_plan'], array_column($decoded['capability_data']['direct'], 'plan_type'));
    }
}
