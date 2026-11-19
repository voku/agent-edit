<?php

declare(strict_types=1);

namespace voku\AgentEdit\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentEdit\EditEngine;
use voku\AgentEdit\Plan\RenamePlanDocument;
use voku\AgentEdit\Receipt\ApplyRequest;
use voku\AgentEdit\Tests\Support\CachedAgentMapBuilder;
use voku\AgentEdit\Tests\Support\TemporaryProject;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\IndexWriter;
use voku\AgentMap\Rename\MethodRenamePlanner;

/**
 * Regression: an interface method rename binds its edits to every implementation and override in the plan's `family`.
 * The decoder accepted family-bound edits only for parameter renames, so every safe interface-method plan from
 * agent-map was refused with "not bound to the declared target identity" and nothing could be applied.
 */
final class MethodFamilyRenameTest extends TestCase
{
    private TemporaryProject $project;

    protected function setUp(): void
    {
        $this->project = new TemporaryProject([
            'src/PriceSource.php' => <<<'PHP'
<?php

declare(strict_types=1);

namespace Shop;

interface PriceSource
{
    public function amount(): int;
}

PHP,
            'src/Service.php' => <<<'PHP'
<?php

declare(strict_types=1);

namespace Shop;

class Service implements PriceSource
{
    public function amount(): int
    {
        return 3;
    }
}

PHP,
            'src/Subscription.php' => <<<'PHP'
<?php

declare(strict_types=1);

namespace Shop;

final class Subscription extends Service
{
    public function amount(): int
    {
        return parent::amount() * 2;
    }
}

PHP,
            'src/Coupon.php' => <<<'PHP'
<?php

declare(strict_types=1);

namespace Shop;

final class Coupon
{
    public function amount(): int
    {
        return 1;
    }
}

PHP,
            'src/Cart.php' => <<<'PHP'
<?php

declare(strict_types=1);

namespace Shop;

final class Cart
{
    public function due(PriceSource $source, Coupon $coupon): int
    {
        return $source->amount() - $coupon->amount();
    }
}

PHP,
        ]);
        $root = escapeshellarg($this->project->root);
        exec("git -C {$root} init -q && git -C {$root} add -A && git -C {$root} -c user.email=t@t -c user.name=t commit -qm init");
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function testAnInterfaceMethodRenameAppliesAcrossItsFamilyAndLeavesTheUnrelatedMethodAlone(): void
    {
        $plan = $this->plan();
        $family = $plan['family'];
        sort($family);
        self::assertSame(['method:Shop\\PriceSource::amount', 'method:Shop\\Service::amount', 'method:Shop\\Subscription::amount'], $family);

        $bundle = $this->project->path('.agent-edit/receipts/family');
        $receipt = (new EditEngine())->applyWithReceipt($this->request($bundle));

        self::assertTrue($receipt->succeeded());
        self::assertStringContainsString('public function price(): int;', $this->project->read('src/PriceSource.php'));
        self::assertStringContainsString('public function price(): int', $this->project->read('src/Service.php'));
        self::assertStringContainsString('return parent::price() * 2;', $this->project->read('src/Subscription.php'));
        self::assertStringContainsString('return $source->price() - $coupon->amount();', $this->project->read('src/Cart.php'));
        self::assertStringContainsString('public function amount(): int', $this->project->read('src/Coupon.php'));

        $this->writeMap();
        self::assertSame('passed', (new EditEngine())->verify($this->project->root, $bundle, '.agent-map/php-symbols.json')['status']);
    }

    public function testAFamilyWithoutTheTargetIsRefused(): void
    {
        $plan = $this->plan();
        $plan['family'] = array_values(array_filter($plan['family'], static fn (string $member): bool => $member !== $plan['target_id']));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Method rename target is not part of its declared method family.');

        RenamePlanDocument::fromArray($plan);
    }

    public function testAFamilyMemberWithAnotherMethodNameIsRefused(): void
    {
        $plan = $this->plan();
        $plan['family'][] = 'method:Shop\\Coupon::discount';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Method rename family member does not share the target method name.');

        RenamePlanDocument::fromArray($plan);
    }

    public function testAnEditBoundOutsideTheFamilyIsStillRefused(): void
    {
        $plan = $this->plan();
        $plan['edits'][0]['symbol_id'] = 'method:Shop\\Coupon::amount';
        $before = $this->project->read('src/Cart.php');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not bound to the declared target identity');
        try {
            RenamePlanDocument::fromArray($plan);
        } finally {
            self::assertSame($before, $this->project->read('src/Cart.php'));
        }
    }

    public function testAnEmptyFamilyIsRefused(): void
    {
        $plan = $this->plan();
        $plan['family'] = [];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Method rename plan requires non-empty method-family evidence.');

        RenamePlanDocument::fromArray($plan);
    }

    /** @return array<string, mixed> */
    private function plan(): array
    {
        $plan = (new MethodRenamePlanner())->plan($this->writeMap(), 'Shop\PriceSource::amount', 'price')->toArray();
        self::assertSame('safe', $plan['status'] ?? null, json_encode($plan) ?: '');
        file_put_contents($this->project->path('plan.json'), json_encode($plan, JSON_THROW_ON_ERROR));

        return $plan;
    }

    private function request(string $bundle): ApplyRequest
    {
        return new ApplyRequest(
            repositoryRoot: $this->project->root,
            planPath: $this->project->path('plan.json'),
            mapIndexPath: $this->project->path('.agent-map/php-symbols.json'),
            mapRoot: $this->project->root,
            outputDirectory: $bundle,
            label: 'family',
            dryRun: false,
        );
    }

    private function writeMap(): AgentMapIndex
    {
        $map = CachedAgentMapBuilder::build($this->project->root, ['src']);
        if (!is_dir($this->project->path('.agent-map'))) {
            mkdir($this->project->path('.agent-map'));
        }
        (new IndexWriter())->write($map, $this->project->path('.agent-map/php-symbols.json'));

        return $map;
    }
}
