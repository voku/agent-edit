#!/usr/bin/env python3
"""Generated fixture for the learning-loop experiment: does a workflow that makes agents write durable knowledge into
the repository help the next agent?

    python3 tools/route-experiment/fixtures/loop_rename.py DIR

The tree is the hierarchy-rename tree plus a second PriceSource method, `currency()`, so that one policy can apply to
two tasks in turn. That policy: PriceSource is a published API, and a rename keeps the old name as a deprecated alias.
- Step 1 renames `amount()` to `price()`; a reviewer note in the task states the policy.
- Step 2, done by a fresh agent on step 1's result, renames `currency()` to `currencyCode()`; nothing states the
  policy, unless step 1 wrote it into the repository.

`Coupon` has an unrelated `currency()`, called once (in Ledger), so the step 2 rename has a trap of its own.
The tree starts with a MEMORY.md that holds one unrelated row, and a src/Context/ directory with no rules.
"""
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from hierarchy_rename import files as hierarchy_files  # noqa: E402


def add_method(source: str, method: str) -> str:
    end = source.rstrip().rindex('}')
    return source[:end].rstrip('\n') + '\n' + method + source[end:]


CURRENCY = '''
    public function currency(): string
    {
        return '{code}';
    }
'''


def generate() -> dict[str, str]:
    tree = hierarchy_files('amount')
    tree['src/Pricing/PriceSource.php'] = tree['src/Pricing/PriceSource.php'].replace(
        '    public function amount(): int;\n',
        '    public function amount(): int;\n\n    /** ISO 4217 code of the amount. */\n    public function currency(): string;\n',
    )
    for path, code in [('src/Pricing/Product.php', 'EUR'), ('src/Pricing/Service.php', 'EUR'), ('src/Pricing/Bundle.php', 'EUR')]:
        tree[path] = add_method(tree[path], CURRENCY.replace('{code}', code))
    tree['src/Promo/Coupon.php'] = add_method(tree['src/Promo/Coupon.php'], CURRENCY.replace('{code}', 'XPT'))
    # Family currency() calls: Display (nullable product), Quote (source); the Coupon call is in Ledger.
    tree['src/Checkout/Display.php'] = tree['src/Checkout/Display.php'].replace(
        "return ['amount' => $amount, 'label' => sprintf('amount: %s', $amount ?? '-')];",
        "$currency = $product === null ? '-' : $product->currency();\n\n        return ['amount' => $amount, 'label' => sprintf('amount: %s %s', $amount ?? '-', $currency)];",
    )
    tree['src/Checkout/Display.php'] = tree['src/Checkout/Display.php'].replace(
        '@return array{amount: int|null, label: string}', '@return array{amount: int|null, label: string}')
    tree['src/Checkout/Quote.php'] = tree['src/Checkout/Quote.php'].replace(
        '        return min($item->amount(), $card->amount());',
        "        if ($item->currency() !== 'EUR') {\n            return 0;\n        }\n\n        return min($item->amount(), $card->amount());",
    )
    tree['src/Report/Ledger.php'] = tree['src/Report/Ledger.php'].replace(
        '        $this->amount += $source->amount() - $coupon->amount();',
        "        $this->amount += $source->amount() - ($coupon->currency() === 'XPT' ? $coupon->amount() : 0);",
    )
    tree['MEMORY.md'] = '''# Repository memory

Reviewed, durable rules for this repository. Each row names where the rule is checked.

| Subject | Durable rule | Canonical home |
| --- | --- | --- |
| Coupons are discounts | `Coupon::amount()` and `GiftCard::amount()` are discounts and credits, not prices; they are never part of the PriceSource family. | `src/Promo/` |
'''
    tree['src/Context/.gitkeep'] = ''
    return tree


def write(target: Path) -> None:
    for path, content in generate().items():
        file = target / path
        file.parent.mkdir(parents=True, exist_ok=True)
        file.write_text(content)


if __name__ == '__main__':
    write(Path(sys.argv[1]))
