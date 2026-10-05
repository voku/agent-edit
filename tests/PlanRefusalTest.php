<?php

declare(strict_types=1);

namespace voku\AgentEdit\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentEdit\Plan\ClassConstantRemovalPlanDocument;
use voku\AgentEdit\Plan\ClassMovePlanDocument;
use voku\AgentEdit\Plan\MethodMovePlanDocument;
use voku\AgentEdit\Plan\MethodRemovalPlanDocument;
use voku\AgentEdit\Plan\PlanRefusal;
use voku\AgentEdit\Plan\PropertyRemovalPlanDocument;
use voku\AgentEdit\Plan\RenamePlanDocument;

/** A refused plan explains itself with the owner's own evidence, so a caller never has to open the plan file. */
final class PlanRefusalTest extends TestCase
{
    /** @return iterable<string, array{class-string, string, string}> */
    public static function families(): iterable
    {
        yield 'rename' => [RenamePlanDocument::class, 'method_rename_plan', 'Rename plan'];
        yield 'class move' => [ClassMovePlanDocument::class, 'class_move_plan', 'Class move plan'];
        yield 'method move' => [MethodMovePlanDocument::class, 'method_move_plan', 'Method move plan'];
        yield 'method removal' => [MethodRemovalPlanDocument::class, 'method_removal_plan', 'Method removal plan'];
        yield 'property removal' => [PropertyRemovalPlanDocument::class, 'property_removal_plan', 'Property removal plan'];
        yield 'class constant removal' => [ClassConstantRemovalPlanDocument::class, 'class_constant_removal_plan', 'Class-constant removal plan'];
    }

    /** @param class-string $document */
    #[DataProvider('families')]
    public function testABlockedPlanNamesItsStatusAndTheMapBlocker(string $document, string $type, string $label): void
    {
        $plan = $this->plan($type, 'blocked', ['Method is called at src/Provider/MapRecallProvider.php:312-312 (phpstan_resolved).']);

        $message = $this->refusal($document, $plan);

        self::assertStringContainsString($label, $message);
        self::assertStringContainsString('no source was changed', $message);
        self::assertStringContainsString('src/Provider/MapRecallProvider.php:312-312', $message, 'the call site that blocks the plan is in the message');
    }

    public function testReviewOnlyPlansNameTheirBlindSpots(): void
    {
        $plan = $this->plan('method_removal_plan', 'review_required', [], blindSpots: ['dynamic call of $this->{$name}() in src/X.php:9']);
        $plan['status'] = 'safe';

        $message = $this->refusal(MethodRemovalPlanDocument::class, $plan);

        self::assertStringContainsString('requires explicit review', $message);
        self::assertStringContainsString('blind spots: dynamic call', $message);
    }

    public function testEvidenceIsBoundedAndCountsWhatItLeftOut(): void
    {
        $blockers = [];
        for ($i = 1; $i <= 5; ++$i) {
            $blockers[] = 'caller ' . $i . ' ' . str_repeat('x', 400);
        }

        $message = PlanRefusal::because('Plan is not safe.', ['status' => 'blocked', 'blockers' => $blockers])->getMessage();

        self::assertStringContainsString('caller 1', $message);
        self::assertStringContainsString('caller 3', $message);
        self::assertStringNotContainsString('caller 4', $message);
        self::assertStringContainsString('(+2 more)', $message);
        self::assertLessThan(1000, strlen($message));
    }

    public function testAPlanWithoutEvidenceKeepsTheBareRefusal(): void
    {
        self::assertSame('Plan is not safe.', PlanRefusal::because('Plan is not safe.', [])->getMessage());
    }

    /**
     * @param class-string $document
     * @param array<string, mixed> $plan
     */
    private function refusal(string $document, array $plan): string
    {
        try {
            $document::fromArray($plan);
        } catch (RuntimeException $exception) {
            return $exception->getMessage();
        }

        self::fail('Expected the plan to be refused.');
    }

    /**
     * @param list<string> $blockers
     * @param list<string> $blindSpots
     * @return array<string, mixed>
     */
    private function plan(string $type, string $status, array $blockers, array $blindSpots = []): array
    {
        return [
            'type' => $type,
            'contract_version' => '1.0',
            'status' => $status,
            'target_id' => 'method:Demo\\Service::legacy',
            'stale_evidence' => [],
            'blockers' => $blockers,
            'blind_spots' => $blindSpots,
        ];
    }
}
