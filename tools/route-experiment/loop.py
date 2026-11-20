#!/usr/bin/env python3
"""Learning-loop experiment: does a workflow that makes agents write durable knowledge into the repository help the
next agent? (see docs/route-experiment.md, Run 8)

    python3 tools/route-experiment/loop.py prepare-step1 --arm L0|L1 --out RUN/step1 --itp-context ITP
    python3 tools/route-experiment/loop.py prepare-step2 --from RUN/step1 --out RUN/step2
    python3 tools/route-experiment/loop.py grade --out RUN/stepN [--ledger results.jsonl]

The fixture is fixtures/loop_rename.py. Step 1 renames `amount()` to `price()`. Its task carries a reviewer note: the
API is published, so keep the old name as a deprecated alias. Step 2 is a fresh agent on step 1's final tree. It
renames `currency()` to `currencyCode()` and gets no note, so the policy reaches it only if step 1 wrote it into the
repository.

Both arms get the same project-context paragraph in AGENTS.md: read MEMORY.md, use itp-context-summarize. Arm L1 adds
a required workflow step: before finishing, record every durable rule learned during the task in MEMORY.md and as an
ItpContext #[Rule] on the symbol it governs. Grading executes candidate-written code: run it only in a disposable
environment.
"""
import argparse
import hashlib
import json
import re
import shutil
import subprocess
import sys
from pathlib import Path

KIT = Path(__file__).resolve().parent
sys.path.insert(0, str(KIT))
from prepare import CONTEXT_GUIDANCE, FIXED_ENV, install_itp_context, run, tree_digest  # noqa: E402

CAPTURE_RULE = (
    '## Workflow: record durable knowledge (required)\n\n'
    'Before you finish a task, record every durable project rule you learned while doing it, whether from the code, '
    'the task or a reviewer. Add a row to `MEMORY.md` with the rule and where it is checked. If the rule is about a '
    'specific symbol, also attach it as an ItpContext `#[Rule]`: a case in a rule enum under `src/Context/` that '
    'implements `ItpContext\\Contract\\RuleIdentifier`, with a `RuleDef` that states the rule, why, and '
    '`changeGuidance`. Check it with `tools/itp/bin/itp-context-summarize <file>`. A task is not finished without '
    'this step.\n'
)
TASK1 = (
    'Rename the interface method `Shop\\Pricing\\PriceSource::amount()` to `price()`, together with every '
    'implementation and override of it, and update every call of it. Other methods that happen to be called '
    '`amount()` stay as they are.\n\n'
    'Reviewer note from the payments team: PriceSource is a published API. Partner code outside this repository '
    'calls its methods by name, so any rename must keep the old name on every implementing class as a `@deprecated` '
    'alias that delegates to the new method, for one release.\n'
)
TASK2 = (
    'Rename the interface method `Shop\\Pricing\\PriceSource::currency()` to `currencyCode()`, together with every '
    'implementation and override of it, and update every call of it. Other methods that happen to be called '
    '`currency()` stay as they are.\n'
)
BEHAVIOUR = r'''
spl_autoload_register(static function (string $class): void {
    $file = getcwd() . '/src/' . str_replace('\\', '/', substr($class, strlen('Shop\\'))) . '.php';
    if (is_file($file)) { require $file; }
});
$catalog = new Shop\Repo\Catalog();
$promotions = new Shop\Repo\Promotions();
echo implode('|', [
    (new Shop\Checkout\Cart($catalog, $promotions))->due(3, 'SAVE'),
    (new Shop\Checkout\Quote($catalog, $promotions))->covered('support'),
    (new Shop\Checkout\Totals())->net([$catalog->product(1), $catalog->bundle()], [$promotions->giftCard()]),
    ((new Shop\Checkout\Refund($catalog))->quoter())(),
    json_encode((new Shop\Checkout\Display($catalog))->line(true)),
    json_encode((new Shop\Checkout\Display($catalog))->line(false)),
    (new Shop\Report\Ledger())->book($catalog->source('plan'), $promotions->coupon('X')),
]);
'''
POLICY = r'''
spl_autoload_register(static function (string $class): void {
    $file = getcwd() . '/src/' . str_replace('\\', '/', substr($class, strlen('Shop\\'))) . '.php';
    if (is_file($file)) { require $file; }
});
// An alias passes when the effective old method (inherited is fine) is marked deprecated, its body forwards to
// $this->new(...), and both return the same value; equal values alone would let a copied implementation pass.
$aliasOk = static function (object $source, string $old, string $new): bool {
    if (!method_exists($source, $new) || !method_exists($source, $old)) {
        return false;
    }
    $method = new ReflectionMethod($source, $old);
    $deprecated = str_contains((string) $method->getDocComment(), '@deprecated') || $method->getAttributes('Deprecated') !== [];
    $lines = array_slice(file((string) $method->getFileName()), $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1);
    $forwards = preg_match('/\$this\s*->\s*' . preg_quote($new, '/') . '\s*\(/', implode('', $lines)) === 1;

    return $deprecated && $forwards && $source->$old() === $source->$new();
};
$sources = [new Shop\Pricing\Product(500), new Shop\Pricing\Service(50, 3), new Shop\Pricing\Subscription(20, 2, 6),
    new Shop\Pricing\Bundle([new Shop\Pricing\Product(100)])];
$pairs = array_chunk(array_slice($argv, 1), 2);
$family = [];
foreach ($sources as $source) {
    foreach ($pairs as [$old, $new]) {
        $family[$source::class . '::' . $old] = $aliasOk($source, $old, $new);
    }
}
[$old, $new] = end($pairs);
$coupon = new Shop\Promo\Coupon(5);
echo json_encode([
    'family_alias' => $family,
    'interface_declares_new' => method_exists('Shop\Pricing\PriceSource', $new),
    'coupon_untouched' => method_exists($coupon, $old) && !method_exists($coupon, $new),
]);
'''
STEPS = {
    1: {'old': 'amount', 'new': 'price', 'old_calls_outside_pricing': 4, 'new_calls_outside_pricing': 6},
    2: {'old': 'currency', 'new': 'currencyCode', 'old_calls_outside_pricing': 1, 'new_calls_outside_pricing': 2},
}


def commit_baseline(repo: Path) -> str:
    (repo / '.gitignore').write_text('tools/itp/calls.log\n.agent-map/\n.agent-edit/\n')
    run(['git', 'init', '-q', '.'], repo)
    run(['git', 'add', '-A'], repo)
    run(['git', 'commit', '-qm', 'baseline'], repo, FIXED_ENV)
    return run(['git', 'rev-parse', 'HEAD'], repo).stdout.strip()


def prepare_step1(args) -> None:
    out = Path(args.out).resolve()
    if out.exists():
        sys.exit(f'{out} exists')
    repo = out / 'repo'
    repo.mkdir(parents=True)
    run(['python3', str(KIT / 'fixtures/loop_rename.py'), str(repo)], KIT)
    install_itp_context(repo / 'tools/itp', Path(args.itp_context).resolve())
    agents = CONTEXT_GUIDANCE + ('\n' + CAPTURE_RULE if args.arm == 'L1' else '')
    (repo / 'AGENTS.md').write_text(agents)
    manifest = {'kind': 'route_experiment_loop', 'step': 1, 'arm': args.arm, 'workspace_commit': commit_baseline(repo),
                'source_digest': tree_digest(repo / 'src'), 'agents_md_bytes': len(agents.encode())}
    (out / 'task.md').write_text(TASK1)
    (out / 'manifest.json').write_text(json.dumps(manifest, indent=2) + '\n')
    shutil.copytree(repo, out / 'baseline', ignore=shutil.ignore_patterns('.git'))
    print(json.dumps(manifest))


def prepare_step2(args) -> None:
    source = Path(getattr(args, 'from')).resolve()
    out = Path(args.out).resolve()
    if out.exists():
        sys.exit(f'{out} exists')
    step1 = json.loads((source / 'manifest.json').read_text())
    repo = out / 'repo'
    shutil.copytree(source / 'repo', repo, ignore=shutil.ignore_patterns('.git', 'calls.log', '.agent-map', '.agent-edit'))
    manifest = {'kind': 'route_experiment_loop', 'step': 2, 'arm': step1['arm'], 'from': str(source),
                'workspace_commit': commit_baseline(repo), 'source_digest': tree_digest(repo / 'src')}
    (out / 'task.md').write_text(TASK2)
    (out / 'manifest.json').write_text(json.dumps(manifest, indent=2) + '\n')
    shutil.copytree(repo, out / 'baseline', ignore=shutil.ignore_patterns('.git'))
    print(json.dumps(manifest))


def php(root: Path, script: str, *argv: str) -> str:
    result = subprocess.run(['php', '-r', script, '--', *argv], cwd=root, capture_output=True, text=True)
    return result.stdout if result.returncode == 0 else 'error: ' + (result.stdout + result.stderr)[-400:]


def calls(root: Path, name: str) -> int:
    """`->name(` call tokens in src/ outside src/Pricing (where only declarations and aliases live)."""
    count = 0
    for file in (root / 'src').rglob('*.php'):
        if 'Pricing' in file.relative_to(root / 'src').parts:
            continue
        count += len(re.findall(r'->\s*' + re.escape(name) + r'\s*\(', file.read_text()))
    return count


def capture_evidence(repo: Path, baseline: Path) -> dict:
    memory_before = (baseline / 'MEMORY.md').read_text() if (baseline / 'MEMORY.md').exists() else ''
    memory_after = (repo / 'MEMORY.md').read_text() if (repo / 'MEMORY.md').exists() else ''
    added = memory_after.replace(memory_before, '') if memory_after.startswith(memory_before.rstrip()) else memory_after
    rule_enums = [str(p.relative_to(repo)) for p in (repo / 'src/Context').glob('*.php')] if (repo / 'src/Context').exists() else []
    attributes = sorted(str(p.relative_to(repo)) for p in (repo / 'src').rglob('*.php') if '#[Rule(' in p.read_text())
    summary = subprocess.run(['php', 'tools/itp/lib/bin/itp-context-summarize', 'src/Pricing/PriceSource.php'], cwd=repo,
                             capture_output=True, text=True) if (repo / 'tools/itp').exists() else None
    return {
        'memory_changed': memory_after != memory_before,
        'memory_mentions_policy': bool(re.search(r'alias|published|deprecat', added, re.I)),
        'rule_enums': rule_enums,
        'rule_attributes': attributes,
        'price_source_summary_has_rule': summary is not None and summary.returncode == 0 and '### [' in summary.stdout,
    }


def grade(args) -> None:
    out = Path(args.out).resolve()
    manifest = json.loads((out / 'manifest.json').read_text())
    step = STEPS[manifest['step']]
    repo = out / 'repo'
    want = php(out / 'baseline', BEHAVIOUR)
    got = php(repo, BEHAVIOUR)
    # Step 2 must also keep step 1's amount() -> price() aliases: the published API covers both renames.
    pairs = [STEPS[1]['old'], STEPS[1]['new']] + ([step['old'], step['new']] if manifest['step'] == 2 else [])
    policy_raw = php(repo, POLICY, *pairs)
    try:
        policy = json.loads(policy_raw)
    except json.JSONDecodeError:
        policy = {'error': policy_raw}
    checks = {
        'behaviour unchanged': not want.startswith('error') and got == want,
        'every price source keeps the old name as a deprecated alias that forwards to the new': bool(policy.get('family_alias')) and all(policy['family_alias'].values()),
        'the interface declares the new name': policy.get('interface_declares_new') is True,
        'unrelated Coupon method untouched': policy.get('coupon_untouched') is True,
        'internal callers use the new name': calls(repo, step['new']) == step['new_calls_outside_pricing']
            and calls(repo, step['old']) == step['old_calls_outside_pricing'],
    }
    row = {
        'experiment': 'loop', 'step': manifest['step'], 'arm': manifest['arm'], 'run_dir': out.parent.name + '/' + out.name,
        'correct': all(checks.values()),
        # Correct apart from the alias: what an agent that did not know the policy would produce.
        'correct_without_policy': checks['behaviour unchanged'] and checks['unrelated Coupon method untouched'] and checks['internal callers use the new name'] and checks['the interface declares the new name'],
        'checks': checks, 'policy': policy, 'behaviour': got[-200:],
    }
    if manifest['step'] == 1:
        row['capture'] = capture_evidence(repo, out / 'baseline')
    itp_log = repo / 'tools/itp/calls.log'
    row['itp_calls'] = [line.split('\t', 2)[1] + ' ' + line.split('\t', 2)[2] for line in itp_log.read_text().splitlines() if line.count('\t') >= 2] if itp_log.exists() else []
    run_record = json.loads((out / 'run.json').read_text()) if (out / 'run.json').exists() else {}
    for key in ('total_tokens', 'tool_uses', 'duration_ms'):
        row[key] = run_record.get(key)
    print(json.dumps(row, indent=2))
    if args.ledger:
        with open(args.ledger, 'a') as ledger:
            ledger.write(json.dumps(row, sort_keys=True) + '\n')


def main() -> None:
    parser = argparse.ArgumentParser()
    sub = parser.add_subparsers(dest='command', required=True)
    one = sub.add_parser('prepare-step1')
    one.add_argument('--arm', required=True, choices=['L0', 'L1'])
    one.add_argument('--out', required=True)
    one.add_argument('--itp-context', required=True)
    two = sub.add_parser('prepare-step2')
    two.add_argument('--from', required=True)
    two.add_argument('--out', required=True)
    gr = sub.add_parser('grade')
    gr.add_argument('--out', required=True)
    gr.add_argument('--ledger')
    args = parser.parse_args()
    {'prepare-step1': prepare_step1, 'prepare-step2': prepare_step2, 'grade': grade}[args.command](args)


if __name__ == '__main__':
    main()
