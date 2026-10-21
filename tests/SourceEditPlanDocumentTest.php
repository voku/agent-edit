<?php

declare(strict_types=1);

namespace voku\AgentEdit\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentEdit\Plan\SourceEditPlanDocument;

final class SourceEditPlanDocumentTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function plan(): array
    {
        return [
            'type' => 'source_edit_plan',
            'contract_version' => '1.0',
            'target_id' => 'fix-add',
            'edits' => [['path' => 'src/A.php', 'find' => 'a', 'replace' => 'b']],
        ];
    }

    public function testParsesEditsCreatesAndDefaults(): void
    {
        $plan = self::plan();
        $plan['creates'] = [['path' => 'tests/ATest.php', 'content' => '<?php']];
        $plan['edits'][] = ['path' => 'src/B.php', 'find' => 'x', 'replace' => '', 'occurrence' => 2, 'expected_source_sha256' => 'sha256:' . str_repeat('a', 64)];
        $plan['edits'][] = ['path' => 'src/C.php', 'find' => 'y', 'replace' => 'z', 'all' => true];

        $document = SourceEditPlanDocument::fromArray($plan);

        self::assertSame('fix-add', $document->targetId);
        self::assertFalse($document->allowSignatureChange);
        self::assertCount(3, $document->edits);
        self::assertNull($document->edits[0]->occurrence);
        self::assertFalse($document->edits[0]->all);
        self::assertSame(2, $document->edits[1]->occurrence);
        self::assertSame('', $document->edits[1]->replace);
        self::assertTrue($document->edits[2]->all);
        self::assertSame('tests/ATest.php', $document->creates[0]->path);
    }

    public function testBackslashPathsAreNormalizedToForwardSlashes(): void
    {
        $plan = self::plan();
        $plan['edits'][0]['path'] = 'src\\Deep\\A.php';

        self::assertSame('src/Deep/A.php', SourceEditPlanDocument::fromArray($plan)->edits[0]->path);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidPlans(): iterable
    {
        $with = static fn (array $patch): array => array_replace_recursive(self::plan(), $patch);

        yield 'wrong type' => [['type' => 'method_rename_plan'] + self::plan(), 'expected source_edit_plan'];
        yield 'future version' => [$with(['contract_version' => '2.0']), 'contract version'];
        yield 'missing target' => [array_diff_key(self::plan(), ['target_id' => 1]), 'target_id'];
        yield 'blank target' => [$with(['target_id' => '   ']), 'target_id'];
        yield 'huge target' => [$with(['target_id' => str_repeat('x', 201)]), 'target_id'];
        yield 'non-bool allow' => [$with(['allow_signature_change' => 'yes']), 'allow_signature_change'];
        yield 'unknown plan key' => [$with(['status' => 'safe']), 'unknown key(s) status'];
        yield 'misspelled edit key' => [$with(['edits' => [['replacement' => 'b']]]), 'unknown key(s) replacement'];
        yield 'unknown create key' => [$with(['creates' => [['path' => 'a.php', 'content' => '', 'mode' => '0755']]]), 'unknown key(s) mode'];
        yield 'nothing to do' => [['edits' => []] + self::plan(), 'at least one edit or one create'];
        yield 'edits not a list' => [['edits' => ['a' => []]] + self::plan(), 'must be lists'];
        yield 'empty find' => [$with(['edits' => [['find' => '']]]), 'non-empty string find'];
        yield 'non-string replace' => [$with(['edits' => [['replace' => 5]]]), 'string replace'];
        yield 'no-op edit' => [$with(['edits' => [['find' => 'same', 'replace' => 'same']]]), 'replaces text with itself'];
        yield 'nul in find' => [$with(['edits' => [['find' => "a\0b"]]]), 'NUL byte'];
        yield 'occurrence zero' => [$with(['edits' => [['occurrence' => 0]]]), 'occurrence must be an integer >= 1'];
        yield 'occurrence string' => [$with(['edits' => [['occurrence' => '1']]]), 'occurrence must be an integer'];
        yield 'occurrence and all' => [$with(['edits' => [['occurrence' => 1, 'all' => true]]]), 'both occurrence and all'];
        yield 'non-bool all' => [$with(['edits' => [['all' => 1]]]), 'all must be a boolean'];
        yield 'bad digest' => [$with(['edits' => [['expected_source_sha256' => 'abc']]]), 'sha256:<64 hex>'];
        yield 'absolute path' => [$with(['edits' => [['path' => '/etc/passwd']]]), 'relative to the project root'];
        yield 'windows drive path' => [$with(['edits' => [['path' => 'C:/x.php']]]), 'relative to the project root'];
        yield 'dot dot' => [$with(['edits' => [['path' => 'src/../../x.php']]]), 'canonical'];
        yield 'dot segment' => [$with(['edits' => [['path' => './src/A.php']]]), 'canonical'];
        yield 'double slash' => [$with(['edits' => [['path' => 'src//A.php']]]), 'canonical'];
        yield 'git dir' => [$with(['edits' => [['path' => '.git/config']]]), '.git/'];
        yield 'receipt dir' => [$with(['edits' => [['path' => '.agent-edit/receipts/x/execution.json']]]), '.agent-edit/'];
        yield 'create nul' => [$with(['creates' => [['path' => 'a.php', 'content' => "\0"]]]), 'NUL byte'];
        yield 'create without content' => [$with(['creates' => [['path' => 'a.php']]]), 'string content'];
        yield 'create twice' => [$with(['creates' => [['path' => 'n.php', 'content' => ''], ['path' => 'n.php', 'content' => '']]]), 'more than once'];
        yield 'edit and create same file' => [$with(['creates' => [['path' => 'src/A.php', 'content' => '']]]), 'both edits and creates'];
    }

    /**
     * @param array<string, mixed> $plan
     */
    #[DataProvider('invalidPlans')]
    public function testRejectsInvalidPlansWithAnActionableMessage(array $plan, string $message): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($message);

        SourceEditPlanDocument::fromArray($plan);
    }

    public function testLimitsTheSizeOfOnePlan(): void
    {
        $plan = self::plan();
        $plan['edits'] = array_fill(0, SourceEditPlanDocument::MAX_EDITS + 1, ['path' => 'src/A.php', 'find' => 'a', 'replace' => 'b']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('split it');

        SourceEditPlanDocument::fromArray($plan);
    }

    public function testSchemaDescribesExactlyTheKeysTheParserAccepts(): void
    {
        $schema = SourceEditPlanDocument::schema();
        $json = json_encode($schema, JSON_THROW_ON_ERROR);

        self::assertJson($json);
        self::assertFalse($schema['additionalProperties']);
        self::assertSame(['allow_signature_change', 'contract_version', 'creates', 'edits', 'target_id', 'type'], $this->sorted(array_keys($schema['properties'])));
        self::assertSame(
            ['all', 'expected_source_sha256', 'find', 'occurrence', 'path', 'replace'],
            $this->sorted(array_keys($schema['properties']['edits']['items']['properties'])),
        );
        self::assertSame(['content', 'path'], $this->sorted(array_keys($schema['properties']['creates']['items']['properties'])));
        self::assertSame(['path', 'find', 'replace'], $schema['properties']['edits']['items']['required']);
    }

    /**
     * @param list<int|string> $keys
     * @return list<string>
     */
    private function sorted(array $keys): array
    {
        $keys = array_map('strval', $keys);
        sort($keys);

        return $keys;
    }
}
