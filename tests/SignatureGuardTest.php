<?php

declare(strict_types=1);

namespace voku\AgentEdit\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhp;
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
            ['class:Demo\Level', 'method:Demo\Level::label', 'class:Demo\Helps', 'method:Demo\Helps::need', 'case:Demo\Level::Low'],
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

    /** @return iterable<string, array{string, string, string}> */
    public static function memberChanges(): iterable
    {
        $class = static fn (string $body, string $head = 'final class A'): string => "<?php\nnamespace D;\n$head\n{\n$body\n}\n";

        yield 'public constant removed' => [$class("    public const X = 1;\n    public const Y = 2;"), $class('    public const Y = 2;'), 'constant `D\A::X` was removed or renamed'];
        yield 'constant without visibility renamed' => [$class('    const X = 1;'), $class('    const Z = 1;'), 'constant `D\A::X` was removed or renamed'];
        yield 'protected constant removed' => [$class('    protected const X = 1;'), $class(''), 'constant `D\A::X` was removed or renamed'];
        yield 'one name of a multi constant removed' => [$class('    public const X = 1, Y = 2;'), $class('    public const X = 1;'), 'constant `D\A::Y` was removed or renamed'];
        yield 'constant narrowed to private' => [$class('    public const X = 1;'), $class('    private const X = 1;'), 'constant `D\A::X` was removed or renamed'];
        yield 'constant visibility widened' => [$class('    protected const X = 1;'), $class('    public const X = 1;'), 'constant `D\A::X` signature changed from `protected` to `public`'];
        yield 'constant becomes final' => [$class('    public const X = 1;'), $class('    final public const X = 1;'), 'constant `D\A::X` signature changed from `public` to `public final`'];
        yield 'constant type added' => [$class('    public const X = 1;'), $class('    public const int X = 1;'), 'constant `D\A::X` signature changed from `public` to `public int`'];
        yield 'enum case removed' => [$class("    case One;\n    case Two;", 'enum A'), $class('    case Two;', 'enum A'), 'case `D\A::One` was removed or renamed'];
        yield 'enum case renamed' => [$class('    case One;', 'enum A'), $class('    case Uno;', 'enum A'), 'case `D\A::One` was removed or renamed'];
        yield 'backed enum value changed' => [$class("    case One = 'a';", 'enum A: string'), $class("    case One = 'b';", 'enum A: string'), "case `D\\A::One` signature changed from `= 'a'` to `= 'b'`"];
        yield 'enum case gains a backing value' => [$class('    case One;', 'enum A'), $class('    case One = 1;', 'enum A'), 'case `D\A::One` signature changed from `case` to `= 1`'];
        yield 'backed enum value loses a space inside the string' => [$class("    case One = 'a b';", 'enum A: string'), $class("    case One = 'ab';", 'enum A: string'), "case `D\\A::One` signature changed from `= 'a b'` to `= 'ab'`"];
        yield 'backed enum value changes whitespace inside a double-quoted string' => [$class('    case One = "a  b";', 'enum A: string'), $class('    case One = "a b";', 'enum A: string'), 'case `D\\A::One` signature changed from `= "a  b"` to `= "a b"`'];
        yield 'public property removed' => [$class('    public int $x = 1;'), $class(''), 'property `D\A::$x` was removed or renamed'];
        yield 'untyped property without value removed' => [$class('    public $x;'), $class(''), 'property `D\A::$x` was removed or renamed'];
        yield 'var property removed' => [$class('    var $x;'), $class(''), 'property `D\A::$x` was removed or renamed'];
        yield 'one name of a multi property removed' => [$class('    public $a, $b;'), $class('    public $a;'), 'property `D\A::$b` was removed or renamed'];
        yield 'property type changed' => [$class('    public int $x = 1;'), $class('    public string $x = "";'), 'property `D\A::$x` signature changed from `public int` to `public string`'];
        yield 'property type removed' => [$class('    public ?int $x = null;'), $class('    public $x = null;'), 'property `D\A::$x` signature changed from `public ?int` to `public`'];
        yield 'property becomes readonly' => [$class('    public int $x;'), $class('    public readonly int $x;'), 'property `D\A::$x` signature changed from `public int` to `public readonly int`'];
        yield 'property becomes static' => [$class('    public int $x = 0;'), $class('    public static int $x = 0;'), 'property `D\A::$x` signature changed from `public int` to `public static int`'];
        yield 'property narrowed to private' => [$class('    protected int $x = 0;'), $class('    private int $x = 0;'), 'property `D\A::$x` was removed or renamed'];
        yield 'trait property removed' => [$class('    public int $x = 0;', 'trait T'), $class('', 'trait T'), 'property `D\T::$x` was removed or renamed'];
        yield 'interface constant removed' => [$class('    public const X = 1;', 'interface I'), $class('', 'interface I'), 'constant `D\I::X` was removed or renamed'];
        yield 'braced namespace owner' => ["<?php\nnamespace D {\n    class A\n    {\n        public \$x;\n    }\n}\n", "<?php\nnamespace D {\n    class A\n    {\n    }\n}\n", 'property `D\A::$x` was removed or renamed'];
    }

    #[DataProvider('memberChanges')]
    public function testNonPrivateConstantsEnumCasesAndPropertiesAreApi(string $before, string $after, string $expected): void
    {
        self::assertSame([$expected], (new SignatureGuard())->changes($before, $after));
    }

    /** @return iterable<string, array{string, string}> */
    public static function memberNonChanges(): iterable
    {
        $class = static fn (string $body, string $head = 'final class A'): string => "<?php\nnamespace D;\n$head\n{\n$body\n}\n";

        yield 'private constant removed' => [$class('    private const X = 1;'), $class('')];
        yield 'private property removed or renamed' => [$class('    private int $x = 0;'), $class('    private int $y = 0;')];
        yield 'constant value changed' => [$class('    public const X = 1;'), $class('    public const X = 2 + 3;')];
        yield 'property default changed' => [$class('    public int $x = 1;'), $class('    public int $x = 2;')];
        yield 'constant and property added' => [$class(''), $class("    public const X = 1;\n    public int \$x = 0;")];
        yield 'enum case added' => [$class('    case One;', 'enum A'), $class("    case One;\n    case Two;", 'enum A')];
        yield 'reformatting' => [$class('    public  ?int   $x = 1;'), $class("    public\n        ?int\n        \$x = 1;")];
        yield 'comments between modifiers and name' => [$class('    public int $x = 1;'), $class('    public /* c */ int $x = 1; // why')];
        yield 'enum value reformatted outside strings' => [$class("    case One = 'a b' . PHP_EOL;", 'enum A: string'), $class("    case One =\n        'a b'   .   PHP_EOL; // why", 'enum A: string')];
        yield 'enum value with a comment between operands' => [$class("    case One = 1 + 2;", 'enum A: int'), $class("    case One = 1 /* c */ + 2;", 'enum A: int')];
        yield 'moved within the class' => [$class("    public int \$x = 1;\n    public const X = 1;"), $class("    public const X = 1;\n    public int \$x = 1;")];
        yield 'anonymous class members' => [$class('    public function f(): object { return new class { public $x; public const Y = 1; }; }'), $class('    public function f(): object { return new class { }; }')];
        yield 'promoted parameter body edit' => [$class('    public function __construct(public readonly int $x) { }'), $class('    public function __construct(public readonly int $x) { echo 1; }')];
    }

    #[DataProvider('memberNonChanges')]
    public function testPrivateMembersValuesReformattingAndAdditionsAreNotApiChanges(string $before, string $after): void
    {
        self::assertSame([], (new SignatureGuard())->changes($before, $after));
    }

    #[RequiresPhp('>= 8.4')]
    public function testAsymmetricPropertyVisibilityIsPartOfThePropertyShape(): void
    {
        $guard = new SignatureGuard();
        $class = static fn (string $body): string => "<?php\nnamespace D;\nclass A\n{\n$body\n}\n";

        // Dropping the narrower setter visibility widens who may write the property.
        self::assertSame(
            ['property `D\A::$v` signature changed from `public private(set) string` to `public string`'],
            $guard->changes($class('    public private(set) string $v = "";'), $class('    public string $v = "";')),
        );
        self::assertSame(
            ['property `D\A::$v` signature changed from `public string` to `public protected(set) string`'],
            $guard->changes($class('    public string $v = "";'), $class('    public protected(set) string $v = "";')),
        );
        self::assertSame(
            ['property `D\A::$v` signature changed from `public protected(set) string` to `public private(set) string`'],
            $guard->changes($class('    public protected(set) string $v = "";'), $class('    public private(set) string $v = "";')),
        );
        self::assertSame(
            [],
            $guard->changes($class('    public private(set) string $v = "";'), $class("    public   private(set)\n        string \$v = 'x';")),
        );
    }

    public function testMemberDeclarationsAreKeyedByOwner(): void
    {
        $source = "<?php\nnamespace D;\nenum E: int\n{\n    case A = 1;\n    public const K = 2;\n}\nclass C\n{\n    public static ?int \$p;\n    private \$hidden;\n}\n";

        self::assertSame(
            ['class:D\E', 'class:D\C', 'case:D\E::A', 'constant:D\E::K', 'property:D\C::$p'],
            array_keys((array) (new SignatureGuard())->declarations($source)),
        );
    }
}
