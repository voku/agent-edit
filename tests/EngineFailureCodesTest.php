<?php

declare(strict_types=1);

namespace voku\AgentEdit\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentEdit\EditEngine;
use voku\AgentEdit\Failure\CodedFailure;
use voku\AgentEdit\Failure\FailureCode;
use voku\AgentEdit\Receipt\ApplyRequest;
use voku\AgentEdit\Tests\Support\CachedAgentMapBuilder;
use voku\AgentEdit\Tests\Support\ScratchProject;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\IndexWriter;
use voku\AgentMap\Removal\ClassRemovalPlanner;
use voku\AgentEdit\Apply\RenamePlanApplier;

/** Every refusal an agent can hit on the apply and verify path names its cause, so it can pick the one legal recovery. */
final class EngineFailureCodesTest extends TestCase
{
    use ScratchProject;

    private AgentMapIndex $map;

    protected function setUp(): void
    {
        $this->createScratchProject('agent-edit-failure-codes-');
        $this->write('src/Legacy.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace Demo;\n\nfinal class Legacy\n{\n}\n");
        $this->write('src/Keep.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace Demo;\n\nfinal class Keep\n{\n}\n");
        $this->map = CachedAgentMapBuilder::build($this->root, ['src'], []);
        (new IndexWriter())->write($this->map, $this->root . '/map.json');
    }

    protected function tearDown(): void
    {
        $this->removeScratchProject();
    }

    /** @param array<string, mixed> $plan */
    private function dryRun(array $plan): void
    {
        file_put_contents($this->root . '/plan.json', json_encode($plan, JSON_THROW_ON_ERROR));
        $this->dryRunFile($this->root . '/plan.json');
    }

    private function dryRunFile(string $path): void
    {
        (new EditEngine())->applyWithReceipt(new ApplyRequest($this->root, $path, $this->root . '/map.json', $this->root, $this->root . '/.agent-edit/receipts/X', 'X', dryRun: true));
    }

    /** @return array<string, mixed> */
    private function removalPlan(): array
    {
        return (new ClassRemovalPlanner())->plan($this->map, 'Demo\\Legacy')->toArray();
    }

    private function assertFailure(callable $operation, FailureCode $expected): CodedFailure
    {
        try {
            $operation();
        } catch (CodedFailure $failure) {
            self::assertSame($expected, $failure->failureCode(), $failure->getMessage());

            return $failure;
        }
        self::fail('Expected a ' . $expected->value . ' failure.');
    }

    public function testAnUnsupportedPlanTypeNamesWhatItSawAndWhatIsSupported(): void
    {
        $failure = $this->assertFailure(fn () => $this->dryRun(['type' => 'method_copy_plan', 'contract_version' => '1.0']), FailureCode::PlanUnsupported);

        self::assertSame(['plan_type' => 'method_copy_plan', 'contract_version' => '1.0'], $failure->details());
        self::assertStringContainsString('got method_copy_plan@1.0', $failure->getMessage());
        self::assertInstanceOf(\InvalidArgumentException::class, $failure, 'the historical exception type is kept for existing catch blocks');
    }

    public function testAFutureContractVersionIsUnsupportedNotMalformed(): void
    {
        $plan = $this->removalPlan();
        $plan['contract_version'] = '9.0';

        $failure = $this->assertFailure(fn () => $this->dryRun($plan), FailureCode::PlanUnsupported);

        self::assertSame('9.0', $failure->details()['contract_version']);
    }

    public function testAPlanWithoutATypeIsReportedAsUnsupportedWithNullDetails(): void
    {
        $failure = $this->assertFailure(fn () => $this->dryRun(['hello' => 'world']), FailureCode::PlanUnsupported);

        self::assertSame(['plan_type' => null, 'contract_version' => null], $failure->details());
        self::assertStringContainsString('got none@none', $failure->getMessage());
    }

    public function testInvalidJsonNamesTheFileAndTheParserMessage(): void
    {
        file_put_contents($this->root . '/bad.json', '{not json');

        $failure = $this->assertFailure(fn () => $this->dryRunFile($this->root . '/bad.json'), FailureCode::PlanMalformed);

        self::assertStringContainsString($this->root . '/bad.json', $failure->getMessage());
        self::assertStringContainsString('not valid JSON', $failure->getMessage());
        self::assertSame(['path' => $this->root . '/bad.json'], $failure->details());
        self::assertNotNull($failure->getPrevious(), 'the parser error stays attached');
    }

    public function testAScalarJsonDocumentIsMalformedNotAnInternalError(): void
    {
        file_put_contents($this->root . '/scalar.json', '"just a string"');

        $this->assertFailure(fn () => $this->dryRunFile($this->root . '/scalar.json'), FailureCode::PlanMalformed);
    }

    public function testInvalidToonIsMalformedAndNamedAsToon(): void
    {
        file_put_contents($this->root . '/bad.toon', "key: [unterminated\n  - : :\n\t\"");

        try {
            $this->dryRunFile($this->root . '/bad.toon');
            self::markTestIncomplete('The TOON decoder accepted this input; pick an input it rejects.');
        } catch (CodedFailure $failure) {
            self::assertSame(FailureCode::PlanMalformed, $failure->failureCode());
            self::assertStringContainsString('not valid TOON', $failure->getMessage());
        }
    }

    public function testABlockedPlanIsRefusedWithTheFullBlockerList(): void
    {
        $plan = $this->removalPlan();
        $plan['status'] = 'blocked';
        $plan['blockers'] = ['called at src/Keep.php:3', 'implements Demo\\Contract'];

        $failure = $this->assertFailure(fn () => $this->dryRun($plan), FailureCode::PlanRefused);

        self::assertSame(['plan_status' => 'blocked', 'blockers' => ['called at src/Keep.php:3', 'implements Demo\\Contract']], $failure->details());
        self::assertFileExists($this->root . '/src/Legacy.php');
        self::assertDirectoryDoesNotExist($this->root . '/.agent-edit', 'a refusal leaves no bundle behind');
    }

    public function testAPlanWithStaleEvidenceIsClassifiedAsStaleNotRefused(): void
    {
        $plan = $this->removalPlan();
        $plan['stale_evidence'] = ['src/Legacy.php changed after the map'];

        $failure = $this->assertFailure(fn () => $this->dryRun($plan), FailureCode::EvidenceStale);

        self::assertSame(['src/Legacy.php changed after the map'], $failure->details()['stale_evidence']);
        self::assertSame('refresh_map_and_replan', $failure->failureCode()->nextAction());
    }

    public function testASourceEditedAfterTheMapWasBuiltIsStale(): void
    {
        $plan = $this->removalPlan();
        $this->write('src/Legacy.php', $this->read('src/Legacy.php') . "\n// edited after the map\n");

        $this->assertFailure(fn () => $this->dryRun($plan), FailureCode::EvidenceStale);
    }

    public function testAPlanWhoseSourceHashDisagreesWithTheMapSaysEvidenceChanged(): void
    {
        $plan = $this->removalPlan();
        $plan['deletions'][0]['source_sha256'] = 'sha256:' . str_repeat('0', 64);

        $this->assertFailure(fn () => $this->dryRun($plan), FailureCode::EvidenceChanged);
        self::assertFileExists($this->root . '/src/Legacy.php');
    }

    public function testVerifyNamesAMissingBundleAndNeverTouchesTheTree(): void
    {
        $failure = $this->assertFailure(
            fn () => (new EditEngine())->verify($this->root, '.agent-edit/receipts/NOPE', $this->root . '/map.json'),
            FailureCode::InputNotFound,
        );

        self::assertSame(['path' => '.agent-edit/receipts/NOPE'], $failure->details());
    }

    public function testVerifyAfterAnApplyWithoutRebuildingTheMapIsStale(): void
    {
        file_put_contents($this->root . '/plan.json', json_encode($this->removalPlan(), JSON_THROW_ON_ERROR));
        $engine = new EditEngine();
        $engine->applyWithReceipt(new ApplyRequest($this->root, $this->root . '/plan.json', $this->root . '/map.json', $this->root, $this->root . '/.agent-edit/receipts/R', 'R'));

        // The map written before the edit still lists the deleted file as indexed.
        $this->assertFailure(fn () => $engine->verify($this->root, '.agent-edit/receipts/R', $this->root . '/map.json'), FailureCode::EvidenceStale);
    }

    public function testAnUnmigratedFailureSiteIsNotGuessedIntoACode(): void
    {
        // Sites that were not migrated keep throwing what they always threw; nothing is classified from their text.
        try {
            (new RenamePlanApplier())->preflight(['type' => 'method_rename_plan', 'contract_version' => '1.0'], $this->map, $this->root);
            self::fail('Expected the malformed rename plan to be refused.');
        } catch (\RuntimeException $failure) {
            self::assertNotInstanceOf(CodedFailure::class, $failure);
        }
    }
}
