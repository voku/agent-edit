<?php

declare(strict_types=1);

namespace voku\AgentEdit\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use voku\AgentEdit\EditEngine;
use voku\AgentEdit\Receipt\ApplyRequest;
use voku\AgentEdit\Tests\Support\TemporaryProject;

/**
 * Several `remove_symbol` edits in one plan must give the file that removing the symbols one plan after the other
 * gives, also when the removed declarations are neighbours (their blank lines are shared).
 */
final class RemoveNeighbouringSymbolsTest extends TestCase
{
    private const SPACED = <<<'PHP'
<?php

namespace App;

final class Box
{
    public const X = 1;

    private int $a = 0;

    /** One. */
    public function one(): int
    {
        return 1;
    }

    public function two(): int
    {
        return 2;
    }

    public function three(): int
    {
        return 3;
    }
}

PHP;

    private const COMPACT = <<<'PHP'
<?php

namespace App;

final class Box
{
    public const X = 1;
    public const Y = 2;
    private int $a = 0;
    private int $b = 0;

    public function one(): int
    {
        return 1;
    }
    public function two(): int
    {
        return 2;
    }
}

PHP;

    private TemporaryProject $project;

    protected function setUp(): void
    {
        $this->project = new TemporaryProject(['src/Box.php' => '']);
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function subsets(): iterable
    {
        $layouts = [
            'spaced' => [self::SPACED, ['Box::X', 'Box::$a', 'Box::one()', 'Box::two()', 'Box::three()']],
            'compact' => [self::COMPACT, ['Box::X', 'Box::Y', 'Box::$a', 'Box::$b', 'Box::one()', 'Box::two()']],
        ];
        foreach ($layouts as $layout => [$source, $symbols]) {
            $count = count($symbols);
            for ($mask = 1; $mask < (1 << $count); ++$mask) {
                $subset = [];
                foreach ($symbols as $index => $symbol) {
                    if (($mask & (1 << $index)) !== 0) {
                        $subset[] = $symbol;
                    }
                }
                if (count($subset) >= 2) {
                    yield $layout . ': ' . implode(', ', $subset) => [$source, $subset];
                }
            }
        }
    }

    /** @param list<string> $symbols */
    #[DataProvider('subsets')]
    public function testOnePlanEqualsOnePlanPerSymbol(string $source, array $symbols): void
    {
        $this->write($source);
        $this->apply(array_map(static fn (string $symbol): array => ['op' => 'remove_symbol', 'path' => 'src/Box.php', 'symbol' => $symbol], $symbols));
        $combined = $this->read();

        $this->write($source);
        foreach ($symbols as $symbol) {
            $this->apply([['op' => 'remove_symbol', 'path' => 'src/Box.php', 'symbol' => $symbol]]);
        }

        self::assertSame($this->read(), $combined);
        self::assertStringNotContainsString("\n\n\n", $combined, 'no doubled blank line');
        self::assertDoesNotMatchRegularExpression('/\{\n\n|\n\n\}/', $combined, 'no blank line inside braces');
    }

    public function testRemovingEveryMemberLeavesAnEmptyClass(): void
    {
        $this->write(self::SPACED);
        $this->apply(array_map(
            static fn (string $symbol): array => ['op' => 'remove_symbol', 'path' => 'src/Box.php', 'symbol' => $symbol],
            ['Box::three()', 'Box::X', 'Box::two()', 'Box::$a', 'Box::one()'],
        ));

        self::assertSame("<?php\n\nnamespace App;\n\nfinal class Box\n{\n}\n", $this->read());
    }

    public function testRemovalsAroundAnEditedMemberDoNotSwallowIt(): void
    {
        $this->write(self::SPACED);
        $this->apply([
            ['op' => 'remove_symbol', 'path' => 'src/Box.php', 'symbol' => 'Box::one()'],
            ['op' => 'replace_body', 'path' => 'src/Box.php', 'symbol' => 'Box::two()', 'code' => 'return 22;'],
            ['op' => 'remove_symbol', 'path' => 'src/Box.php', 'symbol' => 'Box::three()'],
        ]);

        self::assertSame(<<<'PHP'
<?php

namespace App;

final class Box
{
    public const X = 1;

    private int $a = 0;

    public function two(): int
    {
        return 22;
    }
}

PHP, $this->read());
    }

    public function testARemovalOverlappingAnotherKindOfEditStillFails(): void
    {
        $this->write(self::SPACED);

        $this->expectExceptionMessage('Two edits overlap');

        $this->apply([
            ['op' => 'remove_symbol', 'path' => 'src/Box.php', 'symbol' => 'Box::one()'],
            ['op' => 'replace_body', 'path' => 'src/Box.php', 'symbol' => 'Box::one()', 'code' => 'return 11;'],
        ]);
    }

    /** @param list<array<string, mixed>> $edits */
    private function apply(array $edits): void
    {
        $plan = ['type' => 'source_edit_plan', 'contract_version' => '1.0', 'target_id' => 'remove', 'allow_signature_change' => true, 'edits' => $edits];
        $path = $this->project->path('plan-' . bin2hex(random_bytes(3)) . '.json');
        file_put_contents($path, json_encode($plan, JSON_THROW_ON_ERROR));

        $receipt = (new EditEngine())->applyWithReceipt(new ApplyRequest(
            repositoryRoot: $this->project->root,
            planPath: $path,
            mapIndexPath: null,
            mapRoot: $this->project->root,
            outputDirectory: $this->project->path('.agent-edit/receipts/r-' . bin2hex(random_bytes(3))),
            label: 'remove',
            dryRun: false,
        ));
        self::assertTrue($receipt->succeeded());
    }

    private function write(string $source): void
    {
        file_put_contents($this->project->path('src/Box.php'), $source);
    }

    private function read(): string
    {
        return (string) file_get_contents($this->project->path('src/Box.php'));
    }
}
