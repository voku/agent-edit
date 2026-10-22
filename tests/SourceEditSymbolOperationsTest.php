<?php

declare(strict_types=1);

namespace voku\AgentEdit\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentEdit\EditEngine;
use voku\AgentEdit\Plan\SourceEditPlanDocument;
use voku\AgentEdit\Receipt\ApplyRequest;
use voku\AgentEdit\Tests\Support\TemporaryProject;

/** Symbol-addressed edits inside a source_edit_plan, through the same proof, guard, transaction, receipt and undo. */
final class SourceEditSymbolOperationsTest extends TestCase
{
    private const GREETER = <<<'PHP'
<?php

declare(strict_types=1);

namespace App;

use Psr\Log\LoggerInterface;

final class Greeter
{
    /** Says hello. */
    #[\Deprecated]
    public function greet(string $name): string
    {
        return 'Hello ' . $name;
    }

    private function unused(): void
    {
    }
}

PHP;

    private TemporaryProject $project;

    protected function setUp(): void
    {
        $this->project = new TemporaryProject(['src/Greeter.php' => self::GREETER, 'README.md' => "# Demo\n"]);
        $root = escapeshellarg($this->project->root);
        exec("git -C {$root} init -q && git -C {$root} add -A && git -C {$root} -c user.email=t@t -c user.name=t commit -qm init");
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function testSymbolAndTextEditsOfOneFileApplyTogetherVerifyAndUndo(): void
    {
        $receipt = $this->apply([
            ['op' => 'replace_body', 'path' => 'src/Greeter.php', 'symbol' => 'Greeter::greet()', 'code' => "\$name = trim(\$name);\n\nreturn 'Hello ' . \$name;"],
            ['op' => 'remove_symbol', 'path' => 'src/Greeter.php', 'symbol' => 'Greeter::unused()'],
            ['op' => 'add_use', 'path' => 'src/Greeter.php', 'name' => 'App\Model\User'],
            ['op' => 'insert_member', 'path' => 'src/Greeter.php', 'symbol' => 'Greeter', 'position' => 'start', 'code' => 'private int $count = 0;'],
            ['path' => 'README.md', 'find' => '# Demo', 'replace' => '# Greeter demo'],
        ]);

        self::assertTrue($receipt->succeeded());
        self::assertSame(<<<'PHP'
<?php

declare(strict_types=1);

namespace App;

use App\Model\User;
use Psr\Log\LoggerInterface;

final class Greeter
{
    private int $count = 0;

    /** Says hello. */
    #[\Deprecated]
    public function greet(string $name): string
    {
        $name = trim($name);

        return 'Hello ' . $name;
    }
}

PHP, $this->project->read('src/Greeter.php'));

        $engine = new EditEngine();
        self::assertSame('passed', $engine->verify($this->project->root, $this->bundle())['status']);
        $engine->undo($this->project->root, $this->bundle());
        self::assertSame(self::GREETER, $this->project->read('src/Greeter.php'));
        self::assertSame("# Demo\n", $this->project->read('README.md'));
    }

    public function testInsertingAfterAMemberAndRemovingTheNextOneDoNotOverlap(): void
    {
        // Regression: the insertion point after greet() is the start of the blank line that the removal of unused() also
        // claims, so whole-content diff ranges collided and the plan was refused as overlapping.
        $this->apply([
            ['op' => 'insert_member', 'path' => 'src/Greeter.php', 'symbol' => 'Greeter', 'after' => 'greet', 'code' => "public function bye(): string\n{\n    return 'Bye';\n}"],
            ['op' => 'remove_symbol', 'path' => 'src/Greeter.php', 'symbol' => 'Greeter::unused()'],
        ]);

        self::assertStringEndsWith(<<<'PHP'
        return 'Hello ' . $name;
    }

    public function bye(): string
    {
        return 'Bye';
    }
}

PHP, $this->project->read('src/Greeter.php'));
    }

    public function testReplaceSymbolKeepsDocCommentAndAttributes(): void
    {
        $this->apply([['op' => 'replace_symbol', 'path' => 'src/Greeter.php', 'symbol' => 'App\Greeter::greet', 'code' => "public function greet(string \$name): string\n{\n    return 'Hi ' . \$name;\n}"]]);

        self::assertStringContainsString("    /** Says hello. */\n    #[\\Deprecated]\n    public function greet(string \$name): string\n    {\n        return 'Hi ' . \$name;\n    }\n", $this->project->read('src/Greeter.php'));
    }

    public function testRemovingOrChangingPublicApiNeedsAcknowledgement(): void
    {
        foreach ([
            ['op' => 'remove_symbol', 'path' => 'src/Greeter.php', 'symbol' => 'Greeter::greet()'],
            ['op' => 'replace_symbol', 'path' => 'src/Greeter.php', 'symbol' => 'Greeter::greet()', 'code' => 'public function greet(int $name): string { return ""; }'],
        ] as $edit) {
            try {
                $this->apply([$edit], dryRun: true);
                self::fail('An API change must be refused without allow_signature_change.');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('The edit changes an API other files may call in src/Greeter.php', $exception->getMessage());
            }
        }

        self::assertTrue($this->apply([['op' => 'remove_symbol', 'path' => 'src/Greeter.php', 'symbol' => 'Greeter::greet()']], allowSignatureChange: true)->succeeded());
        self::assertStringNotContainsString('function greet', $this->project->read('src/Greeter.php'));
    }

    public function testOverlappingEditsAreRefused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Two edits overlap in src/Greeter.php');

        $this->apply([
            ['op' => 'replace_body', 'path' => 'src/Greeter.php', 'symbol' => 'Greeter::greet()', 'code' => 'return "";'],
            ['path' => 'src/Greeter.php', 'find' => "return 'Hello ' . \$name;", 'replace' => 'return $name;'],
        ], dryRun: true);
    }

    public function testADryRunReportsInvalidPhpWithItsLine(): void
    {
        try {
            $this->apply([['op' => 'replace_body', 'path' => 'src/Greeter.php', 'symbol' => 'Greeter::greet()', 'code' => 'return 1']], dryRun: true);
            self::fail('Invalid PHP must be refused in a dry run.');
        } catch (RuntimeException $exception) {
            self::assertSame("Edited PHP failed syntax validation before publication in src/Greeter.php: Syntax error, unexpected '}', expecting ';' on line 16 (`}`). No file was changed.", $exception->getMessage());
        }
        self::assertSame(self::GREETER, $this->project->read('src/Greeter.php'));
    }

    public function testFindReplaceResultsAreSyntaxCheckedInADryRunToo(): void
    {
        $this->expectExceptionMessage('Edited PHP failed syntax validation before publication in src/Greeter.php');

        $this->apply([['path' => 'src/Greeter.php', 'find' => "return 'Hello ' . \$name;", 'replace' => "return 'Hello ' . \$name"]], dryRun: true);
    }

    public function testResolutionErrorsNameTheEditAndTheDeclaredSymbols(): void
    {
        $this->expectExceptionMessage('edits[0] (replace_body): Symbol `Greeter::bye()` was not found in src/Greeter.php. Declared there: App\Greeter, App\Greeter::greet(), App\Greeter::unused().');

        $this->apply([['op' => 'replace_body', 'path' => 'src/Greeter.php', 'symbol' => 'Greeter::bye()', 'code' => 'return;']], dryRun: true);
    }

    public function testSymbolOperationsOnlyEditPhpFiles(): void
    {
        $this->expectExceptionMessage('edits[0]: README.md is not a .php file; symbol operations only edit PHP. Use find/replace.');

        $this->apply([['op' => 'remove_symbol', 'path' => 'README.md', 'symbol' => 'Demo']], dryRun: true);
    }

    public function testAddUseAfterAnImportThatEndsPhpOnItsLineIsRefusedAndLeavesTheFileUntouched(): void
    {
        $source = "<?php\nnamespace App;\nuse A\\One; ?>\nhtml\n";
        $this->project->write('src/Closing.php', $source);

        try {
            $this->apply([['op' => 'add_use', 'path' => 'src/Closing.php', 'name' => 'Z\\Two']]);
            self::fail('An import appended after a closing tag must be refused.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('replace_text', $exception->getMessage());
        }

        self::assertSame($source, $this->project->read('src/Closing.php'));
    }

    public function testAPlanOfOnlyNoOpEditsChangesNothingAndIsRefused(): void
    {
        $this->expectExceptionMessage('The plan changes nothing');

        $this->apply([['op' => 'add_use', 'path' => 'src/Greeter.php', 'name' => 'Psr\Log\LoggerInterface']], dryRun: true);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidSymbolEdits(): iterable
    {
        yield 'unknown op' => [['op' => 'rename', 'path' => 'a.php'], 'op must be "replace" (find/replace) or one of: replace_symbol'];
        yield 'missing symbol' => [['op' => 'replace_body', 'path' => 'a.php', 'code' => 'x'], '(replace_body) requires a non-empty string symbol'];
        yield 'missing code' => [['op' => 'insert_member', 'path' => 'a.php', 'symbol' => 'A'], '(insert_member) requires a string code'];
        yield 'missing name' => [['op' => 'add_use', 'path' => 'a.php'], '(add_use) requires a non-empty string name'];
        yield 'find on symbol op' => [['op' => 'remove_symbol', 'path' => 'a.php', 'symbol' => 'A', 'find' => 'x'], 'has unknown key(s) find'];
        yield 'symbol on replace' => [['path' => 'a.php', 'find' => 'x', 'replace' => 'y', 'symbol' => 'A'], 'has unknown key(s) symbol'];
        yield 'after and before' => [['op' => 'insert_member', 'path' => 'a.php', 'symbol' => 'A', 'code' => 'x', 'after' => 'a', 'before' => 'b'], 'sets both after and before'];
        yield 'reindent type' => [['op' => 'replace_body', 'path' => 'a.php', 'symbol' => 'A::b()', 'code' => 'x', 'reindent' => 'no'], 'reindent must be a boolean'];
        yield 'bad path' => [['op' => 'remove_symbol', 'path' => '../a.php', 'symbol' => 'A'], 'path is not a canonical relative path'];
    }

    /** @param array<string, mixed> $edit */
    #[DataProvider('invalidSymbolEdits')]
    public function testInvalidSymbolEditsAreRejectedByTheDocument(array $edit, string $message): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($message);

        SourceEditPlanDocument::fromArray(['type' => 'source_edit_plan', 'contract_version' => '1.0', 'target_id' => 't', 'edits' => [$edit]]);
    }

    public function testTheSchemaDescribesEveryEditForm(): void
    {
        $variants = SourceEditPlanDocument::schema()['properties']['edits']['items']['oneOf'];

        self::assertSame(['path', 'find', 'replace'], $variants[0]['required']);
        $byOp = [];
        foreach (array_slice($variants, 1) as $variant) {
            $byOp[$variant['properties']['op']['const']] = $variant;
            self::assertFalse($variant['additionalProperties']);
        }
        self::assertSame(array_keys(SourceEditPlanDocument::SYMBOL_OPERATIONS), array_keys($byOp));
        self::assertSame(['op', 'path', 'symbol', 'code'], $byOp['replace_body']['required']);
        self::assertSame(['op', 'path', 'name'], $byOp['add_use']['required']);
        self::assertArrayHasKey('after', $byOp['insert_member']['properties']);
    }

    /** @param list<array<string, mixed>> $edits */
    private function apply(array $edits, bool $dryRun = false, bool $allowSignatureChange = false): \voku\AgentEdit\Receipt\EditReceipt
    {
        $plan = ['type' => 'source_edit_plan', 'contract_version' => '1.0', 'target_id' => 'symbol edit', 'edits' => $edits];
        if ($allowSignatureChange) {
            $plan['allow_signature_change'] = true;
        }
        $path = $this->project->path('plan-' . bin2hex(random_bytes(3)) . '.json');
        file_put_contents($path, json_encode($plan, JSON_THROW_ON_ERROR));

        return (new EditEngine())->applyWithReceipt(new ApplyRequest(
            repositoryRoot: $this->project->root,
            planPath: $path,
            mapIndexPath: null,
            mapRoot: $this->project->root,
            outputDirectory: $this->bundle(),
            label: 'symbols',
            dryRun: $dryRun,
        ));
    }

    private function bundle(): string
    {
        return $this->project->path('.agent-edit/receipts/symbols');
    }
}
