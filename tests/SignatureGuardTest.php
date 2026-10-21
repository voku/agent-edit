<?php

declare(strict_types=1);

namespace voku\AgentEdit\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentEdit\Apply\SignatureGuard;

final class SignatureGuardTest extends TestCase
{
    private const BASE = <<<'PHP'
<?php

declare(strict_types=1);

namespace Demo;

interface Greeter
{
    public function greet(string $name): string;
}

final class Service implements Greeter
{
    public const VERSION = '1';

    private int $count = 0;

    public function __construct(private readonly string $prefix = 'hi')
    {
    }

    public function greet(string $name): string
    {
        $fn = function (int $x): int {
            return $x + 1;
        };

        return $this->prefix . $name . $fn(1) . "{$name}";
    }

    protected static function build(array $options = []): static
    {
        return new static();
    }

    private function secret(int $a): void
    {
    }
}

function helper(int $a, ?string $b = null): bool
{
    return true;
}
PHP;

    public function testBodyOnlyEditsPrivateMethodsAndAdditionsAreNotApiChanges(): void
    {
        $after = str_replace('return $this->prefix . $name', 'return \strtoupper($this->prefix) . $name', self::BASE);
        $after = str_replace('private function secret(int $a): void', 'private function secret(string $renamed, int $more = 3): int', $after);
        $after = str_replace('private int $count = 0;', "private int \$count = 0;\n    public int \$added = 1;", $after);
        $after .= "\nfunction brandNew(): void\n{\n}\n";

        self::assertSame([], (new SignatureGuard())->changes(self::BASE, $after));
    }

    public function testReportsChangedParameterReturnTypeAndDefault(): void
    {
        $guard = new SignatureGuard();

        $changes = $guard->changes(self::BASE, str_replace('public function greet(string $name): string
    {', 'public function greet(string $name, bool $loud = false): string
    {', self::BASE));
        self::assertCount(1, $changes);
        self::assertStringContainsString('method `Demo\Service::greet` signature changed', $changes[0]);

        $changes = $guard->changes(self::BASE, str_replace('function helper(int $a, ?string $b = null): bool', 'function helper(int $a, ?string $b = null): int', self::BASE));
        self::assertStringContainsString('function `Demo\helper` signature changed', $changes[0]);

        $changes = $guard->changes(self::BASE, str_replace('?string $b = null', '?string $b = "x"', self::BASE));
        self::assertCount(1, $changes);
    }

    public function testReportsRenameAndRemovalOfFunctionsMethodsAndClasses(): void
    {
        $guard = new SignatureGuard();

        $renamedMethod = str_replace('protected static function build(', 'protected static function make(', self::BASE);
        self::assertSame(['method `Demo\Service::build` was removed or renamed'], $guard->changes(self::BASE, $renamedMethod));

        $renamedClass = str_replace('final class Service implements', 'final class Renamed implements', self::BASE);
        $changes = $guard->changes(self::BASE, $renamedClass);
        self::assertContains('class `Demo\Service` was removed or renamed', $changes);
        self::assertContains('method `Demo\Service::greet` was removed or renamed', $changes);

        $withoutHelper = (string) preg_replace('/function helper.*\z/s', '', self::BASE);
        self::assertSame(['function `Demo\helper` was removed or renamed'], $guard->changes(self::BASE, $withoutHelper));
    }

    public function testVisibilityNarrowingToPrivateCountsAsRemoval(): void
    {
        $after = str_replace('protected static function build(', 'private static function build(', self::BASE);

        self::assertSame(['method `Demo\Service::build` was removed or renamed'], (new SignatureGuard())->changes(self::BASE, $after));
    }

    public function testWideningVisibilityAndAddingModifiersChangesTheSignature(): void
    {
        $after = str_replace('protected static function build(', 'public static function build(', self::BASE);

        $changes = (new SignatureGuard())->changes(self::BASE, $after);
        self::assertCount(1, $changes);
        self::assertStringContainsString('signature changed', $changes[0]);
    }

    public function testWhitespaceCommentsAndReformattingAreNotChanges(): void
    {
        $after = str_replace(
            'public function greet(string $name): string
    {',
            "/** docs */\n    public   function greet(\n        string \$name,\n    ) : string // trailing\n    {",
            self::BASE,
        );

        self::assertSame([], (new SignatureGuard())->changes(self::BASE, $after));
    }

    public function testClosuresArrowFunctionsAnonymousClassesAndClassConstantsAreIgnored(): void
    {
        $source = <<<'PHP'
<?php

namespace Demo;

final class Holder
{
    public function run(): object
    {
        $a = fn (int $x): int => $x;
        $b = static function (): void {};

        return new class {
            public function inner(): void
            {
            }
        } ?? self::class;
    }
}
PHP;
        $declarations = (new SignatureGuard())->declarations($source);

        self::assertSame(['class:Demo\Holder', 'method:Demo\Holder::run'], array_keys((array) $declarations));
    }

    public function testBracedNamespacesAndMultipleClassesAreKeyedByOwner(): void
    {
        $source = <<<'PHP'
<?php

namespace A {
    class One { public function go(): void {} }
    function f(): void {}
}

namespace B {
    class One { public function go(int $x): void {} }
}
PHP;
        $declarations = (new SignatureGuard())->declarations($source);

        self::assertSame(
            ['class:A\One', 'method:A\One::go', 'function:A\f', 'class:B\One', 'method:B\One::go'],
            array_keys((array) $declarations),
        );
    }

    public function testUnparseableSourceYieldsNoVerdictInsteadOfAFalseAlarm(): void
    {
        $guard = new SignatureGuard();

        self::assertNull($guard->declarations("<?php\nfinal class {\n"));
        self::assertSame([], $guard->changes(self::BASE, "<?php\nfinal class {\n"));
        self::assertSame([], $guard->changes("<?php\nfinal class {\n", self::BASE));
    }

    public function testInterfaceAndEnumDeclarationsAreTracked(): void
    {
        $source = <<<'PHP'
<?php

namespace Demo;

enum Level: string
{
    case Low = 'low';

    public function label(): string
    {
        return $this->value;
    }
}

trait Helps
{
    abstract public function need(): int;
}
PHP;
        $declarations = (array) (new SignatureGuard())->declarations($source);

        self::assertSame(
            ['class:Demo\Level', 'method:Demo\Level::label', 'class:Demo\Helps', 'method:Demo\Helps::need'],
            array_keys($declarations),
        );
        self::assertSame('abstract public function need ( ) : int', $declarations['method:Demo\Helps::need'] ?? null);
    }

    public function testAttributesByReferenceReturnsAndReadonlyFinalClassesAreHandled(): void
    {
        $source = <<<'PHP'
<?php

namespace Demo;

#[\Attribute]
final readonly class Value
{
    #[Pure]
    public function &ref(array &$items): array
    {
        return $items;
    }

    abstract protected function hook(int $a): void;
}
PHP;
        $declarations = (array) (new SignatureGuard())->declarations($source);

        self::assertSame(['class:Demo\Value', 'method:Demo\Value::ref', 'method:Demo\Value::hook'], array_keys($declarations));
        self::assertSame('public function & ref ( array & $items ) : array', $declarations['method:Demo\Value::ref'] ?? null);
    }

    public function testPromotedConstructorParameterChangesAreSignatureChanges(): void
    {
        $before = "<?php\nnamespace D;\nclass A\n{\n    public function __construct(private int \$a)\n    {\n    }\n}\n";
        $after = "<?php\nnamespace D;\nclass A\n{\n    public function __construct(public int \$a)\n    {\n    }\n}\n";

        $changes = (new SignatureGuard())->changes($before, $after);

        self::assertCount(1, $changes);
        self::assertStringContainsString('method `D\A::__construct` signature changed', $changes[0]);
    }

    public function testNamedFunctionsInsideMethodsAndConditionalFunctionsAreNotTopLevelApi(): void
    {
        $source = <<<'PHP'
<?php

namespace Demo;

if (!function_exists('Demo\maybe')) {
    function maybe(): void
    {
    }
}

final class Holder
{
    public function run(): void
    {
        function nestedNamed(): void
        {
        }
    }
}
PHP;

        self::assertSame(['class:Demo\Holder', 'method:Demo\Holder::run'], array_keys((array) (new SignatureGuard())->declarations($source)));
    }

    public function testGlobalNamespaceDeclarationsAreKeyedWithoutPrefix(): void
    {
        $source = "<?php\nfunction helper(): void {}\nclass Plain { public function go(): void {} }\n";

        self::assertSame(
            ['function:helper', 'class:Plain', 'method:Plain::go'],
            array_keys((array) (new SignatureGuard())->declarations($source)),
        );
    }

    public function testUsingTheClassKeywordAsAConstantAndInStringsIsNotADeclaration(): void
    {
        $source = "<?php\nnamespace D;\nfinal class A\n{\n    public function name(): string\n    {\n        return self::class . static::class . 'class B {}';\n    }\n}\n";

        self::assertSame(['class:D\A', 'method:D\A::name'], array_keys((array) (new SignatureGuard())->declarations($source)));
    }

    public function testClassModifiersExtendsAndImplementsAreApi(): void
    {
        $guard = new SignatureGuard();
        $before = "<?php\nnamespace D;\nfinal class A extends B implements C\n{\n}\n";

        $loosened = $guard->changes($before, "<?php\nnamespace D;\nclass A extends B implements C\n{\n}\n");
        self::assertCount(1, $loosened);
        self::assertStringContainsString('class `D\A` signature changed from `final class A extends B implements C` to `class A extends B implements C`', $loosened[0]);

        self::assertCount(1, $guard->changes($before, "<?php\nnamespace D;\nfinal class A extends X implements C\n{\n}\n"));
        self::assertCount(1, $guard->changes($before, "<?php\nnamespace D;\nfinal class A extends B implements C, E\n{\n}\n"));
        self::assertCount(1, $guard->changes($before, "<?php\nnamespace D;\nfinal class A extends B\n{\n}\n"));
        self::assertSame([], $guard->changes($before, "<?php\nnamespace D;\n\nfinal   class A\n    extends B\n    implements C\n{\n    public \$added = 1;\n}\n"));
    }

    public function testRemovingAUseFunctionImportDoesNotLookLikeAnApiRemoval(): void
    {
        $before = "<?php\nnamespace Demo;\nuse function strlen;\n";
        $after = "<?php\nnamespace Demo;\n";

        self::assertSame([], (new SignatureGuard())->changes($before, $after));
    }
}
