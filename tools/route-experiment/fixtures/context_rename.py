#!/usr/bin/env python3
"""Generated fixture for the `context-rename` case: a rename whose correctness depends on project knowledge.

    python3 tools/route-experiment/fixtures/context_rename.py DIR [baseline|expected] [--context]

It is the hierarchy-rename tree plus a payment gateway that calls price sources by name, read from configuration:
`call_user_func([$source, $settings['price_method']])`. Renaming `PriceSource::amount()` without changing `price_method` in
config/gateway.php fatals at runtime. The neighbouring `amount_field` key is a payload field name and must not change.
The code alone cannot tell the two strings apart, and a static planner cannot see the reference at all: agent-map lists
string callbacks as not observable and plans the rename as safe.

`--context` adds the project knowledge that answers it:
- `MEMORY.md`: reviewed repository rules, one of them about this dispatch.
- `src/Context/PricingRules.php`: a voku/itp-context rule enum.
- `#[Rule]` attributes on PriceSource and PaymentGateway that point at the rule.

Without `--context` none of these exist. The gateway, config and check script are in both variants.
"""
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from hierarchy_rename import HEADER, files as hierarchy_files  # noqa: E402

GATEWAY_RULE_USE = 'use ItpContext\\Attribute\\Rule;\nuse Shop\\Context\\PricingRules;\n'


def generate(variant: str, context: bool) -> dict[str, str]:
    p = 'price' if variant == 'expected' else 'amount'
    tree = hierarchy_files(p)
    rule_attribute = '#[Rule(PricingRules::GatewayDispatchByName)]\n' if context else ''
    rule_use = GATEWAY_RULE_USE if context else ''
    if context:
        tree['src/Pricing/PriceSource.php'] = tree['src/Pricing/PriceSource.php'].replace(
            'interface PriceSource', rule_use + '\n' + rule_attribute + 'interface PriceSource', 1)
    tree['src/Gateway/PaymentGateway.php'] = HEADER.format(ns='Shop\\Gateway') + f'''use Shop\\Pricing\\PriceSource;
{rule_use}
{rule_attribute}final class PaymentGateway
{{
    /** @param array{{price_method: string, amount_field: string, currency: string}} $settings */
    public function __construct(private array $settings)
    {{
    }}

    /**
     * @param list<PriceSource> $sources
     * @return array<string, int|string>
     */
    public function charge(array $sources): array
    {{
        $sum = 0;
        foreach ($sources as $source) {{
            $sum += call_user_func([$source, $this->settings['price_method']]);
        }}

        return [$this->settings['amount_field'] => $sum, 'currency' => $this->settings['currency']];
    }}
}}
'''
    tree['config/gateway.php'] = f'''<?php

declare(strict_types=1);

// Read by Shop\\Gateway\\PaymentGateway.
return [
    'price_method' => '{p}',
    'amount_field' => 'amount',
    'currency' => 'EUR',
];
'''
    tree['checks/gateway_dispatch.php'] = '''<?php

declare(strict_types=1);

// Fails when the configured price method is not a method of every price source.
spl_autoload_register(static function (string $class): void {
    $file = dirname(__DIR__) . '/src/' . str_replace('\\\\', '/', substr($class, strlen('Shop\\\\'))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

$settings = require dirname(__DIR__) . '/config/gateway.php';
foreach (['Product', 'Service', 'Subscription', 'Bundle'] as $class) {
    if (!method_exists('Shop\\\\Pricing\\\\' . $class, $settings['price_method'])) {
        fwrite(STDERR, "Shop\\\\Pricing\\\\{$class} has no method {$settings['price_method']}() configured as price_method\\n");
        exit(1);
    }
}
echo "gateway dispatch ok\\n";
'''
    if context:
        tree['src/Context/PricingRules.php'] = HEADER.format(ns='Shop\\Context') + '''use ItpContext\\Contract\\RuleIdentifier;
use ItpContext\\Enum\\Tier;
use ItpContext\\Model\\RuleDef;

/** Architecture intent of the pricing code that the code alone does not show. */
enum PricingRules implements RuleIdentifier
{
    case GatewayDispatchByName;

    public function getDefinition(): RuleDef
    {
        return match ($this) {
            self::GatewayDispatchByName => new RuleDef(
                statement: 'PaymentGateway calls the PriceSource method named by config/gateway.php `price_method`.',
                tier: Tier::Critical,
                owner: 'Team-Payments',
                rationale: 'The gateway is configured per deployment, so it dispatches by name instead of a typed call. '
                    . 'Static analysis and rename tools do not see this reference.',
                verifiedBy: ['checks/gateway_dispatch.php'],
                refs: ['config/gateway.php'],
                changeGuidance: 'Renaming the PriceSource method means changing `price_method` in config/gateway.php in '
                    . 'the same change. `amount_field` is a payload field name, not a method, and stays. Run '
                    . '`php checks/gateway_dispatch.php` afterwards.',
            ),
        };
    }
}
'''
        tree['MEMORY.md'] = '''# Repository memory

Reviewed, durable rules for this repository. Each row names where the rule is checked.

| Subject | Durable rule | Canonical home |
| --- | --- | --- |
| Gateway dispatch by name | `PaymentGateway::charge()` calls the PriceSource method named by `price_method` in `config/gateway.php`. A PriceSource method rename changes that key in the same change; `amount_field` is a payload field name and stays. | `checks/gateway_dispatch.php`, `Shop\\Context\\PricingRules::GatewayDispatchByName` |
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
