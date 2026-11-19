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
}


def shortcut(case: str, repo: Path) -> None:
    old, new = ('total', 'grandTotal') if case == 'fixture-rename' else ('amount', 'price')
    for path in sorted((repo / 'src').rglob('*.php')):
        text = path.read_text()
        text = re.sub(r'(->|::)' + old + r'\(', r'\1' + new + '(', text)
        path.write_text(re.sub(r'function ' + old + r'\(', 'function ' + new + '(', text))


def grade(out: Path, with_tests: bool) -> dict:
    args = ['python3', str(KIT / 'grade.py'), '--out', str(out)] + ([] if with_tests else ['--no-tests'])
    return json.loads(subprocess.run(args, check=True, capture_output=True, text=True).stdout)


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument('--work', required=True)
    parser.add_argument('--recall-source')
    parser.add_argument('--with-tests', action='store_true', help='also run case test commands (needs Composer access)')
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
        for outcome, want_correct in outcomes.items():
            out = work / f"{case['id']}-{outcome}"
            prepare = ['python3', str(KIT / 'prepare.py'), '--case', case['id'], '--arm', 'A', '--out', str(out)]
            prepare += ['--source', source] if source else []
            prepare += [] if args.with_tests else ['--no-composer']
            subprocess.run(prepare, check=True, capture_output=True, text=True)
            repo = out / 'repo'
            if outcome.startswith('reference'):
                reference(case['id'], repo)
            if outcome == 'native-shortcut':
                shortcut(case['id'], repo)
            if outcome == 'reference+stray':
                (repo / 'NOTES.md').write_text('stray\n')
            row = grade(out, args.with_tests)
            want_unintended = {'reference+stray': ['NOTES.md'], 'native-shortcut': SHORTCUTS.get(case['id'], [])}.get(outcome, [])
            ok = row['correct'] is want_correct and row['unintended_paths'] == want_unintended
            report.append({'case': case['id'], 'outcome': outcome, 'correct': row['correct'], 'unintended_paths': row['unintended_paths'], 'ok': ok})
            if not ok:
                failures.append((case['id'], outcome, row['oracle']))
    print(json.dumps(report, indent=2))
    if failures:
        print('calibration failed: ' + json.dumps(failures), file=sys.stderr)
        sys.exit(1)
    print('route experiment calibration: every graded outcome as expected', file=sys.stderr)


if __name__ == '__main__':
    main()
