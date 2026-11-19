#!/usr/bin/env python3
"""Grade one finished arm of the route experiment (see docs/route-experiment.md).

    python3 tools/route-experiment/grade.py --out runs/fr-B-1 [--ledger results.jsonl] [--no-tests]

Reads <out>/manifest.json, <out>/repo (the final workspace), <out>/baseline, <out>/transcript.jsonl and
<out>/run.json as written by prepare.py and agent-loop's run-arm.sh. Runs the hidden oracle on a copy of the final
tree, so it executes candidate-written code: grade only in a disposable environment such as CI.
Nothing here calls a model.
"""
import argparse
import json
import re
import shutil
import subprocess
import sys
import tempfile
from pathlib import Path

KIT = Path(__file__).resolve().parent
ROUTE_COMMANDS = {
    'agent_map': re.compile(r'(^|[\s/])agent-map\s'),
    'agent_edit': re.compile(r'(^|[\s/])agent-edit\s'),
}


def transcript_events(path: Path) -> list[dict]:
    events = []
    if not path.exists():
        return events
    for line in path.read_text(errors='replace').splitlines():
        try:
            events.append(json.loads(line))
        except json.JSONDecodeError:
            continue
    return events


def behaviour_metrics(events: list[dict]) -> dict:
    """Tool use, retries and cost from the Claude Code stream-json transcript; null where the host reports nothing."""
    tool_calls = 0
    by_tool: dict[str, int] = {}
    failed_tool_results = 0
    route_calls = {name: 0 for name in ROUTE_COMMANDS}
    native_edits = 0
    for event in events:
        message = event.get('message') or {}
        for block in message.get('content') or [] if isinstance(message.get('content'), list) else []:
            if block.get('type') == 'tool_use':
                tool_calls += 1
                name = block.get('name', '?')
                by_tool[name] = by_tool.get(name, 0) + 1
                if name in ('Edit', 'MultiEdit', 'Write'):
                    native_edits += 1
                if name == 'Bash':
                    command = str((block.get('input') or {}).get('command', ''))
                    for route, pattern in ROUTE_COMMANDS.items():
                        if pattern.search(command):
                            route_calls[route] += 1
            elif block.get('type') == 'tool_result' and block.get('is_error'):
                failed_tool_results += 1
    result = next((e for e in reversed(events) if e.get('type') == 'result'), {})
    usage = result.get('usage') or {}
    input_tokens = None
    if usage:
        input_tokens = sum(int(usage.get(k) or 0) for k in ('input_tokens', 'cache_creation_input_tokens', 'cache_read_input_tokens'))
    if route_calls['agent_map'] and route_calls['agent_edit']:
        chosen = 'map+edit'
    elif route_calls['agent_edit']:
        chosen = 'direct'
    elif native_edits:
        chosen = 'native'
    else:
        chosen = 'none'
    return {
        'tool_calls': tool_calls,
        'tool_calls_by_name': by_tool,
        'failed_tool_results': failed_tool_results,
        'route_tool_calls': route_calls,
        'native_edit_calls': native_edits,
        'chosen_route': chosen,
        'input_tokens': input_tokens,
        'output_tokens': int(usage['output_tokens']) if usage.get('output_tokens') is not None else None,
        'turns': result.get('num_turns'),
        'duration_ms': result.get('duration_ms'),
        'cost_usd': result.get('total_cost_usd'),
        'host_reported_error': bool(result.get('is_error')) if result else None,
    }


def logged_route(log: Path, changed: list[str]) -> dict | None:
    """The route from <out>/tool-calls.log (written by the runtime wrappers, outside the workspace), or None when the
    arm had no wrapped tools. A mutating agent-edit call (apply, edit, recover) after an agent-map planner call is
    map+edit; a mutating agent-edit call alone is direct; source changes without either are native."""
    if not log.exists():
        return None
    calls = [line.split('\t') for line in log.read_text().splitlines() if line.count('\t') >= 2]
    # Every agent-map planner subcommand ends in `-plan` (rename-plan, method-removal-plan, ...).
    plan_at = [i for i, c in enumerate(calls) if c[1] == 'agent-map' and re.match(r'\s*\S*-plan\b', c[2])]
    mutation_at = [i for i, c in enumerate(calls) if c[1] == 'agent-edit' and re.match(r'\s*(apply|edit|recover)\b', c[2]) and '--dry-run' not in c[2]]
    # map+edit only when a mutation follows a planner call; a plan built after the edit did not drive it.
    planned = bool(plan_at) and any(i > plan_at[0] for i in mutation_at)
    route = 'map+edit' if planned else 'direct' if mutation_at else 'native' if changed else 'none'
    return {
        'chosen_route': route,
        'route_tool_calls': {
            'agent_map': sum(1 for c in calls if c[1] == 'agent-map'),
            'agent_edit': sum(1 for c in calls if c[1] == 'agent-edit'),
        },
        'route_log': [c[1] + ' ' + c[2] for c in calls],
    }


def suite_completed(output: str) -> bool:
    """PHPUnit printed its final summary: `OK (...)` for a clean run, a `Tests: ...` line otherwise."""
    return re.search(r'^(OK \(|OK, but|Tests: )', output, re.M) is not None


def test_failures(output: str) -> list[str]:
    return sorted({line.split(') ', 1)[1].split(' with data set')[0].strip() for line in output.splitlines() if line[:1].isdigit() and ') ' in line[:6]})


def changed_paths(repo: Path) -> list[str]:
    """Paths changed against the workspace baseline commit, tool state and installs excluded."""
    if not (repo / '.git').exists():
        return []
    subprocess.run(['git', 'add', '-A'], cwd=repo, capture_output=True)
    out = subprocess.run(['git', 'diff', '--cached', '--name-only', 'HEAD'], cwd=repo, capture_output=True, text=True).stdout
    ignored = ('.agent-map/', '.agent-edit/', 'vendor/', 'tools/route-tools/')
    return sorted(p for p in out.splitlines() if p and not p.startswith(ignored) and p not in ('plan.json',))


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument('--out', required=True)
    parser.add_argument('--ledger')
    parser.add_argument('--no-tests', action='store_true', help='skip the case test command (dry grading only)')
    args = parser.parse_args()
    out = Path(args.out).resolve()
    manifest = json.loads((out / 'manifest.json').read_text())
    case = next(c for c in json.loads((KIT / 'cases.json').read_text())['cases'] if c['id'] == manifest['case'])

    with tempfile.TemporaryDirectory() as scratch:
        final = Path(scratch) / 'final'
        shutil.copytree(out / 'repo', final, symlinks=True, ignore=shutil.ignore_patterns('.git'))
        oracle = subprocess.run(['php', str(KIT / case['oracle']), case['id'], str(final), str(out / 'baseline')], capture_output=True, text=True)
        try:
            verdict = json.loads(oracle.stdout.strip().splitlines()[-1])
        except (IndexError, json.JSONDecodeError):
            verdict = {'correct': False, 'ungradeable': True, 'oracle_stderr': oracle.stderr[-2000:]}
        tests = None
        if case.get('test_command') and not args.no_tests:
            # With a vendor shim the suite runs in the workspace itself (the shim points at absolute workspace paths).
            cwd = out / 'repo' if manifest.get('baseline_test_failures') is not None else final
            run = subprocess.run(case['test_command'], cwd=cwd, shell=True, capture_output=True, text=True)
            failures = test_failures(run.stdout)
            baseline = manifest.get('baseline_test_failures')
            # Tests that already fail on the untouched baseline (environment) do not count against the agent.
            # A nonzero exit must be explained by parsed failures; a crash after the summary is not a pass.
            passed = run.returncode == 0 if baseline is None else (
                suite_completed(run.stdout) and set(failures) <= set(baseline) and (run.returncode == 0 or bool(failures)))
            tests = {'exit_code': run.returncode, 'failures': failures, 'baseline_failures': baseline, 'passed': passed, 'tail': run.stdout[-800:]}
            verdict['correct'] = bool(verdict.get('correct')) and passed

    changed = changed_paths(out / 'repo')
    changed = [p for p in changed if not p.startswith('vendor/')]
    unintended = [p for p in changed if p not in case['allowed_paths'] and not (manifest['arm'] == 'C' and p == 'AGENTS.md')]
    run_record = json.loads((out / 'run.json').read_text()) if (out / 'run.json').exists() else {}
    row = {
        'case': manifest['case'],
        'case_kind': manifest['case_kind'],
        'arm': manifest['arm'],
        'expected_route': manifest['expected_route'],
        'run_dir': out.name,
        'ran': (out / 'transcript.jsonl').exists(),
        'timed_out': run_record.get('timed_out'),
        'model': run_record.get('model'),
        'correct': bool(verdict.get('correct')),
        'oracle': verdict,
        'tests': tests,
        'changed_paths': changed,
        'unintended_paths': unintended,
        'instruction_overhead_bytes': manifest['instruction_overhead_bytes'],
        **behaviour_metrics(transcript_events(out / 'transcript.jsonl')),
    }
    # In-session runs have no stream-json transcript: the operator records the host's usage report in run.json, and
    # the route comes from the wrapper log.
    for key in ('total_tokens', 'tool_uses', 'duration_ms'):
        if run_record.get(key) is not None:
            row[key] = run_record[key]
    row['ran'] = row['ran'] or bool(run_record.get('ran'))
    route = logged_route(out / 'tool-calls.log', changed)
    if route is not None:
        row.update(route)
    elif not (out / 'transcript.jsonl').exists():
        row['chosen_route'] = 'native' if changed else 'none'
        row['route_tool_calls'] = {'agent_map': 0, 'agent_edit': 0}
    row['cost_per_success_usd'] = row['cost_usd'] if row['correct'] and row['cost_usd'] is not None else None
    print(json.dumps(row, indent=2))
    if args.ledger:
        with open(args.ledger, 'a') as ledger:
            ledger.write(json.dumps(row, sort_keys=True) + '\n')


if __name__ == '__main__':
    main()
