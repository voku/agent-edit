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
            'method_removal_plan',
            'property_removal_plan',
            'class_constant_removal_plan',
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

        self::assertSame(['method_move_plan'], array_map(static fn (array $row): string => $row['map']['plan_type'], $result['executable']));
        self::assertSame(['method_copy_plan', 'class_move_plan'], array_map(static fn (array $row): string => $row['plan_type'], $result['planned_not_executable']));
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
        self::assertCount(11, $result['executable']);
        $plannedOnly = array_map(static fn (array $row): string => $row['plan_type'], $result['planned_not_executable']);
        foreach ($plannedOnly as $type) {
            // Anything Map plans but agent-edit cannot execute must be a known non-mutation-contract gap, never silent.
            self::assertContains($type, ['method_copy_plan', 'class_scaffold_plan', 'method_scaffold_plan']);
        }
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
            self::assertTrue(is_subclass_of($capability->applier, \voku\AgentEdit\Apply\PlanApplier::class), $capability->planType);
            self::assertTrue(is_subclass_of($capability->verifier, \voku\AgentEdit\Verify\BundleVerifier::class), $capability->planType);
            self::assertSame($capability, (new CapabilityRegistry())->findByRunner($capability->runner) === null ? null : $capability);
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
}
