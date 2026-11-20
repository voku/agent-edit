#!/usr/bin/env python3
"""Generated fixture for the `policy-rename` case: a rename whose correct shape is project policy, not code.

    python3 tools/route-experiment/fixtures/policy_rename.py DIR [baseline|expected|reference] [--context]

It is the hierarchy-rename tree unchanged. The repository's policy is that `PriceSource` is a published API: partner
code outside this repository calls `amount()` on these objects. A rename therefore keeps the old name as a
deprecated alias that delegates to the new one. Nothing in the code shows that. The policy exists only in the project
context, which `--context` adds:
- `MEMORY.md`: one row about the published API;
- `src/Context/PricingRules.php`: a voku/itp-context rule enum;
- a `#[Rule]` attribute on PriceSource.

The oracle requires the alias in every arm, because the policy holds whether or not the agent was told.

`expected` is the plain rename. The oracle compares against it after removing any `amount()` declaration from the
Pricing classes, so every alias shape passes. `reference` adds one valid alias shape, for calibration.
"""
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from hierarchy_rename import HEADER, files as hierarchy_files  # noqa: E402

ALIAS = '''
    /** @deprecated since the price() rename; partner code still calls amount(). */
    public function amount(): int
    {
        return $this->price();
    }
'''
# The classes that declare the family method; Subscription inherits Service's alias.
ALIAS_FILES = ['src/Pricing/Product.php', 'src/Pricing/Service.php', 'src/Pricing/Bundle.php']


def generate(variant: str, context: bool) -> dict[str, str]:
    tree = hierarchy_files('amount' if variant == 'baseline' else 'price')
    if variant == 'reference':
        for path in ALIAS_FILES:
            source = tree[path]
            end = source.rstrip().rindex('}')
            tree[path] = source[:end].rstrip('\n') + '\n' + ALIAS + source[end:]
    if context:
        tree['src/Pricing/PriceSource.php'] = tree['src/Pricing/PriceSource.php'].replace(
            'interface PriceSource',
            'use ItpContext\\Attribute\\Rule;\nuse Shop\\Context\\PricingRules;\n\n#[Rule(PricingRules::PublishedPricingApi)]\ninterface PriceSource',
            1,
        )
        tree['src/Context/PricingRules.php'] = HEADER.format(ns='Shop\\Context') + '''use ItpContext\\Contract\\RuleIdentifier;
use ItpContext\\Enum\\Tier;
use ItpContext\\Model\\RuleDef;

/** Architecture intent of the pricing code that the code alone does not show. */
enum PricingRules implements RuleIdentifier
{
    case PublishedPricingApi;

    public function getDefinition(): RuleDef
    {
        return match ($this) {
            self::PublishedPricingApi => new RuleDef(
                statement: 'PriceSource is a published API: partner code outside this repository calls its methods.',
                tier: Tier::Critical,
                owner: 'Team-Payments',
                rationale: 'Partner integrations are deployed independently and call price objects by method name. '
                    . 'Removing a method breaks them at runtime; nothing in this repository shows those callers.',
                verifiedBy: ['the partner contract tests (outside this repository)'],
                refs: ['MEMORY.md'],
                changeGuidance: 'Renaming a PriceSource method keeps the old name on every implementing class as a '
                    . '`@deprecated` alias that delegates to the new method, for one release. Internal callers move '
                    . 'to the new name.',
            ),
        };
    }
}
'''
        tree['MEMORY.md'] = '''# Repository memory

Reviewed, durable rules for this repository. Each row names where the rule is checked.

| Subject | Durable rule | Canonical home |
| --- | --- | --- |
| Published pricing API | `PriceSource` is a published API: partner code outside this repository calls `amount()` and friends on our price objects. A PriceSource method rename keeps the old name on every implementing class as a `@deprecated` alias that delegates to the new method, for one release; internal callers move to the new name. | `Shop\\Context\\PricingRules::PublishedPricingApi`, partner contract tests (outside this repository) |
| Coupons are discounts | `Coupon::amount()` and `GiftCard::amount()` are discounts and credits, not prices; they are never part of the PriceSource family. | `src/Promo/` |
| Architecture rules | Symbols with a `#[Rule]` attribute carry architecture intent. Run `tools/itp/bin/itp-context-summarize <file>` before changing them. | `src/Context/PricingRules.php` |
'''
    return tree


def write(target: Path, variant: str, context: bool) -> None:
    for path, content in generate(variant, context).items():
        file = target / path
        file.parent.mkdir(parents=True, exist_ok=True)
        file.write_text(content)


if __name__ == '__main__':
    args = [a for a in sys.argv[1:] if a != '--context']
    write(Path(args[0]), args[1] if len(args) > 1 else 'baseline', '--context' in sys.argv[1:])
