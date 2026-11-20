#!/usr/bin/env python3
"""Calibrate the route-experiment kit without any model run (CI runs this).

    python3 tools/route-experiment/calibrate.py --work DIR [--recall-source DIR] [--with-tests]

For every case it prepares arm A and grades simulated outcomes: the untouched workspace must be incorrect, the
reference solution correct, and the reference plus a stray file must report one unintended path. For the two
adversarial renames, the plausible native shortcut (replace every call and declaration of the old name) must be
incorrect and report the unrelated classes as unintended. Exit 1 on any
deviation. Cases whose source is missing are reported as skipped, never as passed.
"""
import argparse
import json
import re
import subprocess
import sys
from pathlib import Path

KIT = Path(__file__).resolve().parent


def replace(repo: Path, path: str, old: str, new: str, count: int = 1) -> None:
    file = repo / path
    text = file.read_text()
    if text.count(old) < 1:
        sys.exit(f'calibration reference does not apply: {old!r} not in {path}')
    file.write_text(text.replace(old, new) if count == 0 else text.replace(old, new, count))


def reference(case: str, repo: Path) -> None:
    if case == 'fixture-rename':
        replace(repo, 'src/Invoice.php', 'public function total()', 'public function grandTotal()')
        replace(repo, 'src/Invoice.php', '$this->total()', '$this->grandTotal()')
        replace(repo, 'src/Checkout.php', '$invoice->total()', '$invoice->grandTotal()')
        replace(repo, 'src/Report.php', '(new Invoice(1, 2))->total()', '(new Invoice(1, 2))->grandTotal()')
    elif case == 'fixture-remove':
        text = (repo / 'src/Invoice.php').read_text()
        start = text.index('    private function legacyTotal')
        end = text.index('    /** @return array{total', start)
        (repo / 'src/Invoice.php').write_text(text[:start] + text[end:])
    elif case == 'fixture-body':
        replace(repo, 'src/Invoice.php', '        return array_sum($this->lines);', '        return array_sum(array_filter($this->lines, static fn (int $line): bool => $line > 0));')
    elif case == 'recall-rename':
        for path in ['src/SelectionResult.php', 'src/MetaJsonRenderer.php', 'src/OutcomeDraftRenderer.php', 'src/SystemBriefingRenderer.php', 'src/ValidationPlanRenderer.php']:
            replace(repo, path, 'toRecallResult', 'asRecallResult', 0)
    elif case == 'hierarchy-rename':
        subprocess.run(['python3', str(KIT / 'fixtures/hierarchy_rename.py'), str(repo), 'expected'], check=True)
    elif case == 'stale-rename':
        subprocess.run(['python3', str(KIT / 'fixtures/hierarchy_rename.py'), str(repo), 'expected-stale'], check=True)
    elif case == 'policy-rename':
        context = ['--context'] if (repo / 'MEMORY.md').exists() else []
        subprocess.run(['python3', str(KIT / 'fixtures/policy_rename.py'), str(repo), 'reference'] + context, check=True)
    elif case == 'context-rename':
        context = ['--context'] if (repo / 'MEMORY.md').exists() else []
        subprocess.run(['python3', str(KIT / 'fixtures/context_rename.py'), str(repo), 'expected'] + context, check=True)
    elif case == 'scale-rename':
        subprocess.run(['python3', str(KIT / 'fixtures/scale_rename.py'), str(repo), 'expected'], check=True)
    elif case == 'recall-collect-rename':
        for path in sorted(p for d in ('src', 'tests') for p in (repo / d).rglob('*.php')):
            text = path.read_text()
            if re.search(r'\bcollect\b', text):
                path.write_text(re.sub(r'\bcollect\b', 'gather', text))
    else:
        sys.exit(f'no reference for {case}')


# The plausible native shortcut per case: replace every call and declaration of the old name, whatever the receiver.
SHORTCUTS = {
    'fixture-rename': ['src/Cart.php'],
    'hierarchy-rename': ['src/Promo/Coupon.php', 'src/Promo/GiftCard.php'],
    # Every unrelated Config class plus the services whose calls are all unrelated.
    'scale-rename': None,
}


def shortcut(case: str, repo: Path) -> None:
    old, new = {'fixture-rename': ('total', 'grandTotal'), 'hierarchy-rename': ('amount', 'price'), 'scale-rename': ('value', 'amount')}[case]
    for path in sorted((repo / 'src').rglob('*.php')):
        text = path.read_text()
        text = re.sub(r'(->|::)' + old + r'\(', r'\1' + new + '(', text)
        path.write_text(re.sub(r'function ' + old + r'\(', 'function ' + new + '(', text))


def simulate_writer(out: Path) -> None:
    """What fixtures/stale_writer.py does during a real run: add its method to the untouched tree, and log it."""
    sys.path.insert(0, str(KIT / 'fixtures'))
    from hierarchy_rename import STALE_PATH, stale_insert
    target = out / 'repo' / STALE_PATH
    target.write_text(stale_insert(target.read_text(), 'amount'))
    (out / 'stale-writer.jsonl').write_text(
        json.dumps({'at': 0, 'event': 'first_open', 'name': Path(STALE_PATH).name}) + '\n'
        + json.dumps({'at': 0, 'event': 'wrote', 'path': STALE_PATH}) + '\n'
    )


def grade(out: Path, with_tests: bool) -> dict:
    args = ['python3', str(KIT / 'grade.py'), '--out', str(out)] + ([] if with_tests else ['--no-tests'])
    return json.loads(subprocess.run(args, check=True, capture_output=True, text=True).stdout)


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument('--work', required=True)
    parser.add_argument('--recall-source')
    parser.add_argument('--with-tests', action='store_true', help='also run case test commands (needs Composer access)')
    parser.add_argument('--itp-context', help='a voku/itp-context checkout, to also calibrate the context arm (E)')
    args = parser.parse_args()
    work = Path(args.work).resolve()
    work.mkdir(parents=True, exist_ok=True)
    failures = []
    report = []
    for case in json.loads((KIT / 'cases.json').read_text())['cases']:
        source = None
        if case['source']['type'] == 'git':
            if not args.recall_source:
                report.append({'case': case['id'], 'skipped': 'no --recall-source'})
                continue
            source = args.recall_source
        outcomes = {'untouched': False, 'reference': True, 'reference+stray': True} | ({'native-shortcut': False} if case['id'] in SHORTCUTS else {})
        if case.get('concurrent_writer'):
            # A stale read that overwrote the writer's method, and a rename that never re-read the file.
            outcomes |= {'stale-overwrite': False, 'missed-concurrent-call': False}
        if case['id'] == 'context-rename':
            # The rename without the configured dispatch name, and with the payload field renamed as well.
            outcomes |= {'config-missed': False, 'field-renamed': False}
        if case['id'] == 'policy-rename':
            # The plain rename that the policy forbids, and an alias that does not delegate.
            outcomes |= {'plain-rename': False, 'broken-alias': False}
        arms = ['A'] + (['E'] if case.get('context') and args.itp_context else [])
        if case.get('context') and not args.itp_context:
            report.append({'case': case['id'], 'arm': 'E', 'skipped': 'no --itp-context'})
        for arm, (outcome, want_correct) in [(arm, item) for arm in arms for item in outcomes.items()]:
            if arm == 'E' and outcome not in ('untouched', 'reference', 'config-missed', 'plain-rename'):
                continue
            out = work / f"{case['id']}-{arm}-{outcome}"
            prepare = ['python3', str(KIT / 'prepare.py'), '--case', case['id'], '--arm', arm, '--out', str(out)]
            prepare += ['--itp-context', args.itp_context] if arm == 'E' else []
            prepare += ['--source', source] if source else []
            prepare += [] if args.with_tests else ['--no-composer']
            subprocess.run(prepare, check=True, capture_output=True, text=True)
            repo = out / 'repo'
            if case.get('concurrent_writer'):
                simulate_writer(out)
            if outcome.startswith('reference'):
                reference(case['id'], repo)
            if outcome == 'stale-overwrite':
                subprocess.run(['python3', str(KIT / 'fixtures/hierarchy_rename.py'), str(repo), 'expected'], check=True)
            if outcome == 'missed-concurrent-call':
                subprocess.run(['python3', str(KIT / 'fixtures/hierarchy_rename.py'), str(repo), 'expected'], check=True)
                cart = repo / 'src/Checkout/Cart.php'
                sys.path.insert(0, str(KIT / 'fixtures'))
                from hierarchy_rename import stale_insert
                cart.write_text(stale_insert(cart.read_text(), 'amount'))
            if outcome in ('config-missed', 'field-renamed'):
                reference(case['id'], repo)
                gateway = repo / 'config/gateway.php'
                text = gateway.read_text()
                if outcome == 'config-missed':
                    text = text.replace("'price_method' => 'price'", "'price_method' => 'amount'")
                else:
                    text = text.replace("'amount_field' => 'amount'", "'amount_field' => 'price'")
                gateway.write_text(text)
            if outcome in ('plain-rename', 'broken-alias'):
                context = ['--context'] if (repo / 'MEMORY.md').exists() else []
                variant = 'expected' if outcome == 'plain-rename' else 'reference'
                subprocess.run(['python3', str(KIT / 'fixtures/policy_rename.py'), str(repo), variant] + context, check=True)
                if outcome == 'broken-alias':
                    product = repo / 'src/Pricing/Product.php'
                    product.write_text(product.read_text().replace('return $this->price();', 'return 0;'))
            if outcome == 'native-shortcut':
                shortcut(case['id'], repo)
            if outcome == 'reference+stray':
                (repo / 'NOTES.md').write_text('stray\n')
            row = grade(out, args.with_tests)
            want_unintended = {'reference+stray': ['NOTES.md'], 'native-shortcut': SHORTCUTS.get(case['id'], [])}.get(outcome, [])
            if want_unintended is None:
                # Too many to list: the shortcut must at least rename every unrelated Config class.
                configs = sorted(str(p.relative_to(repo)) for p in (repo / 'src/Config').glob('*.php'))
                ok = row['correct'] is want_correct and set(configs) <= set(row['unintended_paths'])
            else:
                ok = row['correct'] is want_correct and row['unintended_paths'] == want_unintended
            report.append({'case': case['id'], 'arm': arm, 'outcome': outcome, 'correct': row['correct'], 'unintended_paths': row['unintended_paths'], 'ok': ok})
            if not ok:
                failures.append((case['id'], outcome, row['oracle']))
    print(json.dumps(report, indent=2))
    if failures:
        print('calibration failed: ' + json.dumps(failures), file=sys.stderr)
        sys.exit(1)
    print('route experiment calibration: every graded outcome as expected', file=sys.stderr)


if __name__ == '__main__':
    main()
