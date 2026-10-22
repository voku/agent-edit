<?php

declare(strict_types=1);

namespace voku\AgentEdit\Tests\Symbol;

use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use voku\AgentEdit\Symbol\SymbolEditException;
use voku\AgentEdit\Symbol\PhpSourceEditor;

final class PhpSourceEditorTest extends TestCase
{
    private const SERVICE = <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Service;

use App\Model\User;
use Psr\Log\LoggerInterface;

/** Greets users. */
final class Greeter
{
    public const PREFIX = 'Hi';

    private int $count = 0;

    public function __construct(private LoggerInterface $logger)
    {
    }

    /**
     * Says hello.
     */
    #[\Deprecated]
    public function greet(User $user): string
    {
        $this->count++;

        return self::PREFIX . ' ' . $user->name;
    }

    private function unused(): void
    {
    }
}

PHP;

    // ---------------------------------------------------------------- replace_symbol

    public function testReplaceSymbolKeepsTheExistingDocCommentAndAttributesWhenTheCodeOmitsThem(): void
    {
        $result = $this->edit(self::SERVICE, [
            'op' => 'replace_symbol',
            'symbol' => 'Greeter::greet()',
            'code' => "public function greet(User \$user): string\n{\n    return 'hello';\n}",
        ]);

        self::assertStringContainsString(<<<'PHP'
    /**
     * Says hello.
     */
    #[\Deprecated]
    public function greet(User $user): string
    {
        return 'hello';
    }
PHP, $result['content']);
        $this->assertParses($result['content']);
    }

    public function testReplaceSymbolReplacesTheDocCommentAndAttributesTheCodeBrings(): void
    {
        $result = $this->edit(self::SERVICE, [
            'op' => 'replace_symbol',
            'symbol' => 'App\Service\Greeter::greet',
            'code' => "/** New doc. */\n#[\\Override]\npublic function greet(User \$user): string\n{\n    return 'x';\n}",
        ]);

        self::assertStringContainsString("    /** New doc. */\n    #[\\Override]\n    public function greet(", $result['content']);
        self::assertStringNotContainsString('Says hello', $result['content']);
        self::assertStringNotContainsString('Deprecated', $result['content']);
    }

    public function testReplaceSymbolWithOnlyNewAttributesKeepsTheDocComment(): void
    {
        $result = $this->edit(self::SERVICE, [
            'op' => 'replace_symbol',
            'symbol' => 'Greeter::greet()',
            'code' => "#[\\Override]\npublic function greet(User \$user): string { return 'x'; }",
        ]);

        self::assertStringContainsString("     * Says hello.\n     */\n    #[\\Override]\n    public function greet(User \$user): string { return 'x'; }", $result['content']);
    }

    public function testReplaceSymbolReindentsCodeWrittenAtAnyIndentation(): void
    {
        $code = "            private int \$count = 10;\n";
        $result = $this->edit(self::SERVICE, ['op' => 'replace_symbol', 'symbol' => 'Greeter::$count', 'code' => $code]);

        self::assertStringContainsString("\n    private int \$count = 10;\n", $result['content']);
    }

    public function testReplaceSymbolHandlesConstantsClassesAndFunctions(): void
    {
        self::assertStringContainsString("public const PREFIX = 'Hello';", $this->edit(self::SERVICE, ['op' => 'replace_symbol', 'symbol' => 'Greeter::PREFIX', 'code' => "public const PREFIX = 'Hello';"])['content']);

        $functions = "<?php\n\nnamespace App;\n\nfunction helper(): int\n{\n    return 1;\n}\n";
        self::assertSame("<?php\n\nnamespace App;\n\nfunction helper(): int\n{\n    return 2;\n}\n", $this->edit($functions, ['op' => 'replace_symbol', 'symbol' => 'helper()', 'code' => "function helper(): int\n{\n    return 2;\n}"], 'src/functions.php')['content']);

        $class = $this->edit(self::SERVICE, ['op' => 'replace_symbol', 'symbol' => 'Greeter', 'code' => "final class Greeter\n{\n}"]);
        self::assertStringEndsWith("/** Greets users. */\nfinal class Greeter\n{\n}\n", $class['content']);
    }

    public function testReplaceSymbolRefusesAMultiNameDeclaration(): void
    {
        $this->expectExceptionMessage('is declared together with 1 other name(s) in one statement');

        $this->edit("<?php\nclass A\n{\n    public \$a, \$b;\n}\n", ['op' => 'replace_symbol', 'symbol' => 'A::$a', 'code' => 'public $a;'], 'a.php');
    }

    public function testReplaceSymbolRefusesEmptyCode(): void
    {
        $this->expectExceptionMessage('use remove_symbol to delete a declaration');

        $this->edit(self::SERVICE, ['op' => 'replace_symbol', 'symbol' => 'Greeter::PREFIX', 'code' => "  \n"]);
    }

    public function testReplaceSymbolVerbatimWhenReindentIsDisabled(): void
    {
        $result = $this->edit(self::SERVICE, ['op' => 'replace_symbol', 'symbol' => 'Greeter::PREFIX', 'code' => "public const PREFIX = 'A';", 'reindent' => false]);

        self::assertStringContainsString("    public const PREFIX = 'A';\n", $result['content']);
    }

    // ---------------------------------------------------------------- replace_body

    public function testReplaceBodyKeepsTheSignatureAndIndentsTheBody(): void
    {
        $result = $this->edit(self::SERVICE, ['op' => 'replace_body', 'symbol' => 'Greeter::greet()', 'code' => "if (\$user->name === '') {\n    return '';\n}\n\nreturn 'x';"]);

        self::assertStringContainsString(<<<'PHP'
    #[\Deprecated]
    public function greet(User $user): string
    {
        if ($user->name === '') {
            return '';
        }

        return 'x';
    }
PHP, $result['content']);
        $this->assertParses($result['content']);
    }

    public function testReplaceBodyFindsTheRealBodyBraceDespiteBracesInStringsAndInterpolation(): void
    {
        $source = "<?php\nfunction f(array \$a = ['{' => '}']): string\n{\n    return \"{\$a['{']}\" . '}';\n}\n";
        $result = $this->edit($source, ['op' => 'replace_body', 'symbol' => 'f()', 'code' => "return '';"], 'f.php');

        self::assertSame("<?php\nfunction f(array \$a = ['{' => '}']): string\n{\n    return '';\n}\n", $result['content']);
    }

    public function testReplaceBodyAcceptsAnEmptyBody(): void
    {
        $result = $this->edit(self::SERVICE, ['op' => 'replace_body', 'symbol' => 'Greeter::greet()', 'code' => '']);

        self::assertStringContainsString("    public function greet(User \$user): string\n    {\n    }\n", $result['content']);
    }

    public function testReplaceBodyRefusesAnAbstractMethod(): void
    {
        $this->expectExceptionMessage('has no body (abstract or interface method); use replace_symbol');

        $this->edit("<?php\ninterface I\n{\n    public function run(): void;\n}\n", ['op' => 'replace_body', 'symbol' => 'I::run()', 'code' => 'return;'], 'i.php');
    }

    public function testReplaceBodyRefusesANonCallable(): void
    {
        $this->expectExceptionMessage('is a constant; this operation needs a method or function');

        $this->edit(self::SERVICE, ['op' => 'replace_body', 'symbol' => 'Greeter::PREFIX', 'code' => 'x']);
    }

    // ---------------------------------------------------------------- insert_member

    public function testInsertMemberAtTheEndAddsOneBlankLine(): void
    {
        $result = $this->edit(self::SERVICE, ['op' => 'insert_member', 'symbol' => 'Greeter', 'code' => "public function bye(): string\n{\n    return 'bye';\n}"]);

        self::assertStringEndsWith(<<<'PHP'
    private function unused(): void
    {
    }

    public function bye(): string
    {
        return 'bye';
    }
}

PHP, $result['content']);
    }

    public function testInsertMemberAfterAndBeforeAMember(): void
    {
        $after = $this->edit(self::SERVICE, ['op' => 'insert_member', 'symbol' => 'Greeter', 'after' => '$count', 'code' => 'private bool $ready = false;']);
        self::assertStringContainsString("    private int \$count = 0;\n\n    private bool \$ready = false;\n\n    public function __construct", $after['content']);

        $before = $this->edit(self::SERVICE, ['op' => 'insert_member', 'symbol' => 'Greeter', 'before' => 'greet()', 'code' => 'private const X = 1;']);
        self::assertStringContainsString("    }\n\n    private const X = 1;\n\n    /**\n     * Says hello.", $before['content']);
    }

    public function testInsertMemberAtTheStart(): void
    {
        $result = $this->edit(self::SERVICE, ['op' => 'insert_member', 'symbol' => 'Greeter', 'position' => 'start', 'code' => 'use SomeTrait;']);

        self::assertStringContainsString("final class Greeter\n{\n    use SomeTrait;\n\n    public const PREFIX", $result['content']);
    }

    public function testInsertMemberKeepsTheValueOfAMultiLineString(): void
    {
        $result = $this->edit(self::SERVICE, [
            'op' => 'insert_member',
            'symbol' => 'Greeter',
            'code' => "public function banner(): string\n{\n    return 'one\n  two\nthree';\n}",
        ]);

        self::assertStringContainsString("    public function banner(): string\n    {\n        return 'one\n  two\nthree';\n    }\n}", $result['content']);
        $this->assertParses($result['content']);
    }

    public function testReplaceBodyKeepsTheValueOfAMultiLineString(): void
    {
        $result = $this->edit(self::SERVICE, [
            'op' => 'replace_body',
            'symbol' => 'Greeter::greet()',
            'code' => "return \"a\n  b {\$user->name}\nc\";",
        ]);

        self::assertStringContainsString("        return \"a\n  b {\$user->name}\nc\";\n    }", $result['content']);
        $this->assertParses($result['content']);
    }

    public function testInsertMemberIntoAnEmptyOneLineClassOpensTheBody(): void
    {
        $result = $this->edit("<?php\n\nfinal class Empty_ {}\n", ['op' => 'insert_member', 'symbol' => 'Empty_', 'code' => 'public int $x = 1;'], 'e.php');

        self::assertSame("<?php\n\nfinal class Empty_ {\n    public int \$x = 1;\n}\n", $result['content']);
    }

    public function testInsertMemberIntoAnEmptyMultiLineClass(): void
    {
        $result = $this->edit("<?php\n\nfinal class E\n{\n}\n", ['op' => 'insert_member', 'symbol' => 'E', 'code' => 'public int $x = 1;'], 'e.php');

        self::assertSame("<?php\n\nfinal class E\n{\n    public int \$x = 1;\n}\n", $result['content']);
    }

    public function testInsertMemberIntoAnEnumAndATabIndentedFile(): void
    {
        $enum = $this->edit("<?php\nenum Status: string\n{\n\tcase On = 'on';\n}\n", ['op' => 'insert_member', 'symbol' => 'Status', 'code' => "case Off = 'off';"], 's.php');

        self::assertSame("<?php\nenum Status: string\n{\n\tcase On = 'on';\n\n\tcase Off = 'off';\n}\n", $enum['content']);
    }

    public function testInsertMemberRefusesAnUnknownAnchorWithTheMemberList(): void
    {
        $this->expectExceptionMessage('Symbol `App\Service\Greeter::missing()` was not found in src/Greeter.php. Declared there: App\Service\Greeter, App\Service\Greeter::PREFIX');

        $this->edit(self::SERVICE, ['op' => 'insert_member', 'symbol' => 'Greeter', 'after' => 'missing()', 'code' => 'public $x;']);
    }

    public function testInsertMemberRefusesAnInvalidPosition(): void
    {
        $this->expectExceptionMessage('`position` must be "start" or "end"');

        $this->edit(self::SERVICE, ['op' => 'insert_member', 'symbol' => 'Greeter', 'position' => 'middle', 'code' => 'public $x;']);
    }

    // ---------------------------------------------------------------- remove_symbol

    public function testRemoveTheLastMemberLeavesNoBlankLineBeforeTheClosingBrace(): void
    {
        $result = $this->edit(self::SERVICE, ['op' => 'remove_symbol', 'symbol' => 'Greeter::unused()']);

        self::assertStringEndsWith("        return self::PREFIX . ' ' . \$user->name;\n    }\n}\n", $result['content']);
        self::assertStringEndsWith('references were not checked', $result['summary']);
        $this->assertParses($result['content']);
    }

    public function testRemoveTheFirstMemberLeavesNoBlankLineAfterTheOpeningBrace(): void
    {
        $result = $this->edit(self::SERVICE, ['op' => 'remove_symbol', 'symbol' => 'Greeter::PREFIX']);

        self::assertStringContainsString("final class Greeter\n{\n    private int \$count = 0;\n", $result['content']);
    }

    public function testRemoveAMiddleMemberWithItsDocCommentAndAttributes(): void
    {
        $result = $this->edit(self::SERVICE, ['op' => 'remove_symbol', 'symbol' => 'Greeter::greet()']);

        self::assertStringNotContainsString('Says hello', $result['content']);
        self::assertStringNotContainsString('Deprecated', $result['content']);
        self::assertStringContainsString("    {\n    }\n\n    private function unused(): void", $result['content']);
        $this->assertParses($result['content']);
    }

    public function testRemoveAFunctionAndAClassFromAFile(): void
    {
        $source = "<?php\n\nfunction a(): void\n{\n}\n\nfunction b(): void\n{\n}\n\nfinal class C\n{\n}\n";
        self::assertSame("<?php\n\nfunction b(): void\n{\n}\n\nfinal class C\n{\n}\n", $this->edit($source, ['op' => 'remove_symbol', 'symbol' => 'a()'], 'f.php')['content']);
        self::assertSame("<?php\n\nfunction a(): void\n{\n}\n\nfunction b(): void\n{\n}\n", $this->edit($source, ['op' => 'remove_symbol', 'symbol' => 'C'], 'f.php')['content']);
    }

    public function testRemoveKeepsCrlfLineEndings(): void
    {
        $source = str_replace("\n", "\r\n", self::SERVICE);
        $result = $this->edit($source, ['op' => 'remove_symbol', 'symbol' => 'Greeter::unused()']);

        self::assertSame(0, preg_match("/(?<!\r)\n/", $result['content']), 'Every line ending must stay CRLF.');
        self::assertStringEndsWith("\r\n    }\r\n}\r\n", $result['content']);
        $this->assertParses($result['content']);
    }

    public function testRemoveRefusesAMultiNameConstantStatement(): void
    {
        $this->expectExceptionMessage('remove_symbol would affect all of them');

        $this->edit("<?php\nclass A\n{\n    const X = 1, Y = 2;\n}\n", ['op' => 'remove_symbol', 'symbol' => 'A::X'], 'a.php');
    }

    // ---------------------------------------------------------------- add_use

    public function testAddUseInsertsInSortedPosition(): void
    {
        $result = $this->edit(self::SERVICE, ['op' => 'add_use', 'name' => '\App\Model\Order']);

        self::assertStringContainsString("use App\\Model\\Order;\nuse App\\Model\\User;\nuse Psr\\Log\\LoggerInterface;\n", $result['content']);
        self::assertSame('imported App\Model\Order', $result['summary']);
    }

    public function testAddUseAppendsWhenExistingImportsAreUnsorted(): void
    {
        $source = "<?php\nnamespace A;\n\nuse Z\\Last;\nuse B\\First;\n\nclass C {}\n";
        $result = $this->edit($source, ['op' => 'add_use', 'name' => 'M\\Middle'], 'c.php');

        self::assertSame("<?php\nnamespace A;\n\nuse Z\\Last;\nuse B\\First;\nuse M\\Middle;\n\nclass C {}\n", $result['content']);
    }

    public function testAddUseIsIdempotent(): void
    {
        $result = $this->edit(self::SERVICE, ['op' => 'add_use', 'name' => 'Psr\Log\LoggerInterface']);

        self::assertSame(self::SERVICE, $result['content']);
        self::assertSame('Psr\Log\LoggerInterface is already imported', $result['summary']);
    }

    public function testAddUseRefusesAnAliasConflict(): void
    {
        $this->expectExceptionMessage('already imports App\Model\User as User; set a different `alias`');

        $this->edit(self::SERVICE, ['op' => 'add_use', 'name' => 'Other\User']);
    }

    public function testAddUseWithAliasAndFunctionKind(): void
    {
        $aliased = $this->edit(self::SERVICE, ['op' => 'add_use', 'name' => 'Other\User', 'alias' => 'OtherUser']);
        self::assertStringContainsString("use Other\\User as OtherUser;\n", $aliased['content']);

        $function = $this->edit(self::SERVICE, ['op' => 'add_use', 'name' => 'Vendor\helper', 'kind' => 'function']);
        self::assertStringContainsString("namespace App\\Service;\n\nuse function Vendor\\helper;\n\nuse App\\Model\\User;", $function['content']);
        $this->assertParses($function['content']);
    }

    public function testAddUseIntoANamespaceWithoutImports(): void
    {
        $result = $this->edit("<?php\n\nnamespace App;\n\nfinal class A {}\n", ['op' => 'add_use', 'name' => 'Lib\Thing'], 'a.php');

        self::assertSame("<?php\n\nnamespace App;\n\nuse Lib\\Thing;\n\nfinal class A {}\n", $result['content']);
    }

    public function testAddUseIntoAFileWithoutNamespaceAfterDeclare(): void
    {
        $result = $this->edit("<?php\n\ndeclare(strict_types=1);\n\nfinal class A {}\n", ['op' => 'add_use', 'name' => 'Lib\Thing'], 'a.php');

        self::assertSame("<?php\n\ndeclare(strict_types=1);\n\nuse Lib\\Thing;\n\nfinal class A {}\n", $result['content']);
        $this->assertParses($result['content']);
    }

    public function testAddUseRefusesMultipleAndBracedNamespaces(): void
    {
        foreach ([
            "<?php\nnamespace A;\nclass X {}\nnamespace B;\nclass Y {}\n" => 'declares more than one namespace',
            "<?php\nnamespace A {\n    class X {}\n}\n" => 'uses a braced namespace',
        ] as $source => $message) {
            try {
                $this->edit($source, ['op' => 'add_use', 'name' => 'Lib\Thing'], 'n.php');
                self::fail('Expected refusal: ' . $message);
            } catch (SymbolEditException $exception) {
                self::assertStringContainsString($message, $exception->getMessage());
            }
        }
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidAddUse(): iterable
    {
        yield 'bad name' => [['op' => 'add_use', 'name' => 'App\\'], 'must be a fully qualified name'];
        yield 'bad alias' => [['op' => 'add_use', 'name' => 'A\B', 'alias' => '1x'], '`alias` must be a plain identifier'];
        yield 'bad kind' => [['op' => 'add_use', 'name' => 'A\B', 'kind' => 'trait'], '`kind` must be "class", "function" or "const"'];
    }

    /** @param array<string, mixed> $edit */
    #[DataProvider('invalidAddUse')]
    public function testAddUseValidatesItsFields(array $edit, string $message): void
    {
        $this->expectExceptionMessage($message);

        $this->edit(self::SERVICE, $edit);
    }

    // ---------------------------------------------------------------- symbol resolution

    public function testSymbolsResolveByShortOrQualifiedNameAndCaseInsensitiveMethods(): void
    {
        foreach (['Greeter::GREET()', '\App\Service\Greeter::greet', 'app\service\greeter::greet()'] as $symbol) {
            $result = $this->edit(self::SERVICE, ['op' => 'replace_body', 'symbol' => $symbol, 'code' => 'return "";']);
            self::assertStringContainsString('return "";', $result['content'], $symbol);
        }
    }

    public function testAMethodAndAConstantWithTheSameNameAreAmbiguousWithoutParentheses(): void
    {
        $source = "<?php\nclass A\n{\n    const run = 1;\n\n    public function run(): void\n    {\n    }\n}\n";
        try {
            $this->edit($source, ['op' => 'remove_symbol', 'symbol' => 'A::run'], 'a.php');
            self::fail('Expected ambiguity.');
        } catch (SymbolEditException $exception) {
            self::assertStringContainsString('Symbol `A::run` is ambiguous in a.php: A::run, A::run()', $exception->getMessage());
        }

        self::assertStringNotContainsString('function run', $this->edit($source, ['op' => 'remove_symbol', 'symbol' => 'A::run()'], 'a.php')['content']);
    }

    public function testInvalidPhpIsReportedWithTheParserMessage(): void
    {
        $this->expectExceptionMessage('src/Greeter.php is not valid PHP (Syntax error');

        $this->edit("<?php\nclass {\n", ['op' => 'remove_symbol', 'symbol' => 'A']);
    }

    public function testAnEmptyFileListsNoDeclarations(): void
    {
        $this->expectExceptionMessage('Declared there: no class, function or member declarations.');

        $this->edit("<?php\n", ['op' => 'remove_symbol', 'symbol' => 'A']);
    }

    /**
     * @param array<string, mixed> $edit
     * @return array{content: string, summary: string}
     */
    private function edit(string $content, array $edit, string $path = 'src/Greeter.php'): array
    {
        return (new PhpSourceEditor())->apply($content, $edit + ['path' => $path], $path);
    }

    private function assertParses(string $content): void
    {
        self::assertNotNull((new ParserFactory())->createForHostVersion()->parse($content));
    }
}
