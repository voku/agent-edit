<?php

declare(strict_types=1);

namespace voku\AgentEdit\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentEdit\Cli\FailureReport;
use voku\AgentEdit\Failure\CodedFailure;
use voku\AgentEdit\Failure\EditFailure;
use voku\AgentEdit\Failure\FailureCode;
use voku\AgentEdit\Failure\InvalidInputFailure;
use voku\AgentEdit\Plan\PlanRefusal;

final class FailureCodeTest extends TestCase
{
    public function testEveryCodeHasAUniqueValueAHintAndANextAction(): void
    {
        $values = [];
        foreach (FailureCode::cases() as $code) {
            self::assertMatchesRegularExpression('/^[a-z_]+$/', $code->value);
            self::assertNotSame('', $code->hint(), $code->value);
            self::assertNotSame('', $code->nextAction(), $code->value);
            $values[] = $code->value;
        }
        self::assertSame($values, array_values(array_unique($values)));
    }

    public function testNextActionsNeverNameAnEditThePlanTypeRegistryWouldHaveToOwn(): void
    {
        // CapabilitiesTest forbids plan-type literals outside the registry; an action named like one would trip it silently.
        foreach (FailureCode::cases() as $code) {
            self::assertDoesNotMatchRegularExpression("/_plan$/", $code->nextAction(), $code->value);
        }
    }

    /** Regression: a promoted property named `$code` collides with Exception::$code and fatals on first construction. */
    public function testBothExceptionClassesAreConstructibleAndKeepTheirBuiltInTypes(): void
    {
        $edit = new EditFailure('boom', FailureCode::PlanRefused, ['k' => 1], new RuntimeException('previous'));
        $input = new InvalidInputFailure('bad', FailureCode::Usage);

        self::assertInstanceOf(RuntimeException::class, $edit);
        self::assertInstanceOf(InvalidArgumentException::class, $input);
        self::assertInstanceOf(CodedFailure::class, $edit);
        self::assertInstanceOf(CodedFailure::class, $input);
        self::assertSame(FailureCode::PlanRefused, $edit->failureCode());
        self::assertSame(['k' => 1], $edit->details());
        self::assertSame('previous', $edit->getPrevious()?->getMessage());
        self::assertSame(FailureCode::Usage, $input->failureCode());
        self::assertSame([], $input->details());
        self::assertSame(0, $edit->getCode(), 'the built-in exception code stays untouched');
    }

    public function testPlanRefusalClassifiesStaleEvidenceSeparatelyFromBlockers(): void
    {
        $stale = PlanRefusal::because('Plan contains stale evidence.', ['status' => 'review_required', 'stale_evidence' => ['src/A.php changed']]);
        $blocked = PlanRefusal::because('Plan is blocked.', ['status' => 'blocked', 'blockers' => ['called at src/B.php:3']]);
        $bare = PlanRefusal::because('Plan is not safe.', []);

        self::assertSame(FailureCode::EvidenceStale, $stale->failureCode());
        self::assertSame(FailureCode::PlanRefused, $blocked->failureCode());
        self::assertSame(FailureCode::PlanRefused, $bare->failureCode());
        self::assertSame([], $bare->details());
        self::assertSame(['plan_status' => 'blocked', 'blockers' => ['called at src/B.php:3']], $blocked->details());
    }

    public function testPlanRefusalDetailsCarryMoreThanTheSentenceDoes(): void
    {
        $blockers = [];
        for ($index = 1; $index <= 25; ++$index) {
            $blockers[] = 'caller ' . $index . ' ' . str_repeat('x', 600);
        }

        $refusal = PlanRefusal::because('Plan is blocked.', ['status' => 'blocked', 'blockers' => $blockers]);

        self::assertStringContainsString('(+22 more)', $refusal->getMessage(), 'the message keeps its three-item summary');
        self::assertCount(20, $refusal->details()['blockers'], 'details carry up to twenty items');
        self::assertStringStartsWith('caller 1 ', $refusal->details()['blockers'][0]);
        self::assertLessThanOrEqual(500, mb_strlen($refusal->details()['blockers'][0]));
        self::assertStringEndsWith('…', $refusal->details()['blockers'][0]);
    }

    public function testFailureReportDescribesCodedAndUncodedThrowables(): void
    {
        $coded = FailureReport::describe(new EditFailure('stale', FailureCode::EvidenceStale, ['path' => 'src/A.php']));
        $uncoded = FailureReport::describe(new RuntimeException('who knows'));

        self::assertSame('evidence_stale', $coded['code']);
        self::assertSame('refresh_map_and_replan', $coded['next_action']);
        self::assertSame(['path' => 'src/A.php'], $coded['details']);
        self::assertSame('failed', $uncoded['code'], 'an unclassified failure is reported as such, never guessed');
        self::assertSame('report', $uncoded['next_action']);
        self::assertEquals(new \stdClass(), $uncoded['details']);
        self::assertStringContainsString('"details": {}', FailureReport::encode(['error' => $uncoded]), 'empty details encode as an object, not a list');
    }

    public function testEncodeSurvivesInvalidUtf8InAMessage(): void
    {
        $encoded = FailureReport::encode(['message' => "bad \xB1 byte"]);

        self::assertJson($encoded);
    }
}
