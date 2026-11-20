#!/usr/bin/env python3
"""Generated fixture for the `scale-rename` case: an interface method renamed across a code base too large to read.

    python3 tools/route-experiment/fixtures/scale_rename.py DIR [baseline|expected]

`Ledger\\Entry::value()` has 12 implementations. Eight unrelated classes also declare `value()`. About 100 service
classes call `->value()` around 400 times, and every receiver's type is only known by following typed navigator
methods (`$this->books->journal(2021)->latest(3)->value()`): the same neutral method name returns a ledger entry
in one navigator and an unrelated class in another. Every call line is
unique to its service, so a fix cannot be copied from one file to the next. Generation is deterministic (fixed seed),
and `expected` differs from `baseline` only in the family's `value` -> `amount`.
"""
import random
import sys
from pathlib import Path

HEADER = "<?php\n\ndeclare(strict_types=1);\n\nnamespace {ns};\n\n"
FAMILY = ['Invoice', 'Refund', 'Fee', 'Credit', 'Debit', 'Transfer', 'Payout', 'Charge', 'Rebate', 'Deposit', 'Adjustment', 'Interest']
UNRELATED = ['Setting', 'Option', 'Flag', 'Label', 'Metric', 'Score', 'Weight', 'Quota']
METHOD_NAMES = ['latest', 'first', 'pending', 'open', 'closed', 'primary', 'recent', 'oldest', 'current', 'next']
NAVIGATORS = ['Journal', 'Register', 'Archive', 'Batch', 'Period', 'Account', 'Branch', 'Region']
SERVICES = 100
CALLS_PER_SERVICE = 4


def generate(variant: str) -> dict[str, str]:
    m = 'amount' if variant == 'expected' else 'value'
    rng = random.Random(20261120)
    files: dict[str, str] = {}
    files['src/Ledger/Entry.php'] = HEADER.format(ns='Ledger') + f'''interface Entry
{{
    /** Booked value in cents. */
    public function {m}(): int;
}}
'''
    files['src/Ledger/BaseEntry.php'] = HEADER.format(ns='Ledger') + f'''abstract class BaseEntry implements Entry
{{
    public function __construct(protected int $cents)
    {{
    }}

    public function {m}(): int
    {{
        return $this->cents;
    }}
}}
'''
    for index, name in enumerate(FAMILY):
        # Half extend BaseEntry and override through parent::, half implement Entry directly.
        if index % 2 == 0:
            body = f'''final class {name} extends BaseEntry
{{
    public function {m}(): int
    {{
        return parent::{m}() + {index + 1};
    }}
}}
'''
        else:
            body = f'''final class {name} implements Entry
{{
    public function __construct(private int $cents)
    {{
    }}

    public function {m}(): int
    {{
        return $this->cents * {index + 1};
    }}
}}
'''
        files[f'src/Ledger/{name}.php'] = HEADER.format(ns='Ledger') + body
    for index, name in enumerate(UNRELATED):
        files[f'src/Config/{name}.php'] = HEADER.format(ns='Config') + f'''final class {name}
{{
    public function __construct(private int $raw)
    {{
    }}

    /** Not a ledger value. */
    public function value(): int
    {{
        return $this->raw + {100 + index};
    }}
}}
'''
    # Navigators: typed methods returning one family member or one unrelated class each.
    nav_methods: dict[str, list[tuple[str, str, bool]]] = {}
    for nav in NAVIGATORS:
        # Neutral method names, mapped to a different mix of family and unrelated types in each navigator: the type
        # behind `->latest(3)` depends on which navigator it is called on.
        methods = []
        uses = set()
        lines = []
        names = rng.sample(METHOD_NAMES, 6)
        owners = [(fam, True) for fam in rng.sample(FAMILY, 3)] + [(other, False) for other in rng.sample(UNRELATED, 3)]
        rng.shuffle(owners)
        for name, (owner, family) in zip(names, owners):
            methods.append((name, owner, family))
            uses.add(('Ledger\\' if family else 'Config\\') + owner)
            ctor = f'new {owner}($seed * 3)' if family else f'new {owner}($seed)'
            lines.append(f'''    public function {name}(int $seed): {owner}
    {{
        return {ctor};
    }}
''')
        nav_methods[nav] = methods
        files[f'src/Navigation/{nav}.php'] = HEADER.format(ns='Navigation') + ''.join(f'use {u};\n' for u in sorted(uses)) + f'''
final class {nav}
{{
{chr(10).join(lines)}}}
'''
    files['src/Navigation/Books.php'] = HEADER.format(ns='Navigation') + 'final class Books\n{\n' + ''.join(f'''    public function {nav.lower()}(int $year): {nav}
    {{
        return new {nav}();
    }}

''' for nav in NAVIGATORS).rstrip('\n') + '\n}\n'
    # Services: each call reaches a value() through Books -> navigator -> typed method.
    for number in range(1, SERVICES + 1):
        calls = []
        for call in range(CALLS_PER_SERVICE):
            nav = rng.choice(NAVIGATORS)
            method, owner, family = rng.choice(nav_methods[nav])
            seed = rng.randint(1, 9)
            name = m if family else 'value'
            if call % 2 == 0:
                calls.append(f'$this->books->{nav.lower()}({2020 + number % 7})->{method}({seed})->{name}()')
            else:
                var = f'${method}{call}'
                calls.append((f'        {var} = $this->books->{nav.lower()}({2020 + number % 7})->{method}({seed});\n', f'{var}->{name}()'))
        pre = ''.join(c[0] for c in calls if isinstance(c, tuple))
        exprs = [c[1] if isinstance(c, tuple) else c for c in calls]
        files[f'src/Service/Service{number:03d}.php'] = HEADER.format(ns='Service') + f'''use Navigation\\Books;

final class Service{number:03d}
{{
    public function __construct(private Books $books)
    {{
    }}

    public function total(): int
    {{
{pre}        return {' + '.join(exprs)};
    }}
}}
'''
    files['run.php'] = '''<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $file = __DIR__ . '/src/' . str_replace('\\\\', '/', $class) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

$books = new Navigation\\Books();
$sum = 0;
for ($number = 1; $number <= ''' + str(SERVICES) + '''; ++$number) {
    $class = sprintf('Service\\\\Service%03d', $number);
    $sum += (new $class($books))->total();
}
echo $sum, "\\n";
'''
    return files


def write(target: Path, variant: str) -> None:
    for path, content in generate(variant).items():
        file = target / path
        file.parent.mkdir(parents=True, exist_ok=True)
        file.write_text(content)


if __name__ == '__main__':
    write(Path(sys.argv[1]), sys.argv[2] if len(sys.argv) > 2 else 'baseline')
