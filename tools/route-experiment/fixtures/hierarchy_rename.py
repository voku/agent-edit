#!/usr/bin/env python3
"""Generated fixture for the `hierarchy-rename` case: an interface method with several implementations, and the same
method name on unrelated classes, reached through return types rather than declared variable types.

    python3 tools/route-experiment/fixtures/hierarchy_rename.py DIR [baseline|expected|expected-stale]

`baseline` is the starting tree; `expected` is the same tree with the PriceSource family's `amount` renamed to
`price` and nothing else changed. Text search for `->amount(` cannot tell the two apart: the receiver type is only
known from the repository method that returned it.
"""
import sys
from pathlib import Path

HEADER = "<?php\n\ndeclare(strict_types=1);\n\nnamespace {ns};\n\n"


def files(p: str) -> dict[str, str]:
    """All files, with the family method name `p` (amount in the baseline, price in the expected tree)."""
    f = {}
    f['src/Pricing/PriceSource.php'] = HEADER.format(ns='Shop\\Pricing') + f'''interface PriceSource
{{
    /** Net price in cents. */
    public function {p}(): int;
}}
'''
    f['src/Pricing/Product.php'] = HEADER.format(ns='Shop\\Pricing') + f'''class Product implements PriceSource
{{
    public function __construct(private int $cents)
    {{
    }}

    public function {p}(): int
    {{
        return $this->cents;
    }}
}}
'''
    f['src/Pricing/Service.php'] = HEADER.format(ns='Shop\\Pricing') + f'''class Service implements PriceSource
{{
    public function __construct(protected int $hourly, protected int $hours)
    {{
    }}

    public function {p}(): int
    {{
        return $this->hourly * $this->hours;
    }}
}}
'''
    f['src/Pricing/Subscription.php'] = HEADER.format(ns='Shop\\Pricing') + f'''final class Subscription extends Service
{{
    public function __construct(int $hourly, int $hours, private int $months)
    {{
        parent::__construct($hourly, $hours);
    }}

    public function {p}(): int
    {{
        return parent::{p}() * $this->months;
    }}
}}
'''
    f['src/Pricing/Bundle.php'] = HEADER.format(ns='Shop\\Pricing') + f'''final class Bundle implements PriceSource
{{
    /** @param list<PriceSource> $items */
    public function __construct(private array $items)
    {{
    }}

    public function {p}(): int
    {{
        $sum = 0;
        foreach ($this->items as $item) {{
            $sum += $item->{p}();
        }}

        return intdiv($sum * 9, 10);
    }}
}}
'''
    f['src/Promo/Coupon.php'] = HEADER.format(ns='Shop\\Promo') + '''final class Coupon
{
    public function __construct(private int $amount)
    {
    }

    /** Discount in cents; not a price. */
    public function amount(): int
    {
        return $this->amount;
    }
}
'''
    f['src/Promo/GiftCard.php'] = HEADER.format(ns='Shop\\Promo') + '''final class GiftCard
{
    public function __construct(private int $balance)
    {
    }

    public function amount(): int
    {
        return $this->balance;
    }
}
'''
    f['src/Repo/Catalog.php'] = HEADER.format(ns='Shop\\Repo') + '''use Shop\\Pricing\\Bundle;
use Shop\\Pricing\\PriceSource;
use Shop\\Pricing\\Product;
use Shop\\Pricing\\Service;
use Shop\\Pricing\\Subscription;

final class Catalog
{
    public function product(int $id): Product
    {
        return new Product(100 * $id);
    }

    public function source(string $sku): PriceSource
    {
        return $sku === 'support' ? new Service(50, 3) : new Subscription(20, 2, 6);
    }

    public function bundle(): Bundle
    {
        return new Bundle([$this->product(2), $this->source('support')]);
    }

    public function maybe(bool $found): ?Product
    {
        return $found ? $this->product(7) : null;
    }
}
'''
    f['src/Repo/Promotions.php'] = HEADER.format(ns='Shop\\Repo') + '''use Shop\\Promo\\Coupon;
use Shop\\Promo\\GiftCard;

final class Promotions
{
    public function coupon(string $code): Coupon
    {
        return new Coupon(strlen($code) * 10);
    }

    public function giftCard(): GiftCard
    {
        return new GiftCard(250);
    }
}
'''
    f['src/Checkout/Cart.php'] = HEADER.format(ns='Shop\\Checkout') + f'''use Shop\\Repo\\Catalog;
use Shop\\Repo\\Promotions;

final class Cart
{{
    public function __construct(private Catalog $catalog, private Promotions $promotions)
    {{
    }}

    public function due(int $id, string $code): int
    {{
        return $this->catalog->product($id)->{p}() - $this->promotions->coupon($code)->amount();
    }}
}}
'''
    f['src/Checkout/Quote.php'] = HEADER.format(ns='Shop\\Checkout') + f'''use Shop\\Repo\\Catalog;
use Shop\\Repo\\Promotions;

final class Quote
{{
    public function __construct(private Catalog $catalog, private Promotions $promotions)
    {{
    }}

    public function covered(string $sku): int
    {{
        $item = $this->catalog->source($sku);
        $card = $this->promotions->giftCard();

        return min($item->{p}(), $card->amount());
    }}
}}
'''
    f['src/Checkout/Totals.php'] = HEADER.format(ns='Shop\\Checkout') + f'''use Shop\\Pricing\\PriceSource;
use Shop\\Promo\\GiftCard;

final class Totals
{{
    /**
     * @param list<PriceSource> $sources
     * @param list<GiftCard> $cards
     */
    public function net(array $sources, array $cards): int
    {{
        $prices = array_map(static fn (PriceSource $source): int => $source->{p}(), $sources);
        $credit = array_map(static fn (GiftCard $card): int => $card->amount(), $cards);

        return array_sum($prices) - array_sum($credit);
    }}
}}
'''
    f['src/Checkout/Refund.php'] = HEADER.format(ns='Shop\\Checkout') + f'''use Shop\\Repo\\Catalog;

final class Refund
{{
    public function __construct(private Catalog $catalog)
    {{
    }}

    /** @return \\Closure(): int */
    public function quoter(): \\Closure
    {{
        $bundle = $this->catalog->bundle();

        return static fn (): int => $bundle->{p}();
    }}
}}
'''
    f['src/Checkout/Display.php'] = HEADER.format(ns='Shop\\Checkout') + f'''use Shop\\Repo\\Catalog;

final class Display
{{
    public const FIELD = 'amount';

    public function __construct(private Catalog $catalog)
    {{
    }}

    /** @return array{{amount: int|null, label: string}} */
    public function line(bool $found): array
    {{
        $product = $this->catalog->maybe($found);
        $amount = $product === null ? null : $product->{p}();

        return ['amount' => $amount, 'label' => sprintf('amount: %s', $amount ?? '-')];
    }}
}}
'''
    f['src/Report/Ledger.php'] = HEADER.format(ns='Shop\\Report') + f'''use Shop\\Pricing\\PriceSource;
use Shop\\Promo\\Coupon;

final class Ledger
{{
    private int $amount = 0;

    // The amount() of a coupon is a discount, the price of a source is a charge.
    public function book(PriceSource $source, Coupon $coupon): int
    {{
        $this->amount += $source->{p}() - $coupon->amount();

        return $this->amount;
    }}
}}
'''
    return f


STALE_PATH = 'src/Checkout/Cart.php'


def stale_method(p: str) -> str:
    """The method the stale-rename writer adds to Cart while the agent works: one family call (`p`) and one unrelated
    Coupon call on the same line."""
    return f'''
    public function preview(int $id): int
    {{
        return $this->catalog->product($id)->{p}() - $this->promotions->coupon('PREVIEW')->amount();
    }}
'''


def stale_insert(source: str, p: str) -> str:
    """Insert the stale method before the closing brace of the last class in the file."""
    end = source.rstrip().rindex('}')
    return source[:end].rstrip('\n') + '\n' + stale_method(p) + source[end:]


def write(target: Path, variant: str) -> None:
    """baseline, expected, or expected-stale: expected plus the writer's method with the family call renamed."""
    name = 'price' if variant in ('expected', 'expected-stale') else 'amount'
    for path, content in files(name).items():
        if variant == 'expected-stale' and path == STALE_PATH:
            content = stale_insert(content, 'price')
        file = target / path
        file.parent.mkdir(parents=True, exist_ok=True)
        file.write_text(content)


if __name__ == '__main__':
    write(Path(sys.argv[1]), sys.argv[2] if len(sys.argv) > 2 else 'baseline')
