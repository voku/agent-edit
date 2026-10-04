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
}
