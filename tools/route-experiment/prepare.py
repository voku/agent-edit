#!/usr/bin/env python3
"""Prepare one arm of the route experiment (see docs/route-experiment.md).

    python3 tools/route-experiment/prepare.py --case fixture-rename --arm B --out runs/fr-B-1 [--source DIR] [--no-composer]

Writes <out>/repo (the workspace), <out>/task.md and <out>/manifest.json: the layout that agent-loop's
tools/learning-effect/run-arm.sh runs unchanged. Nothing here calls a model.

Arms (identical task text in every arm):
  A  native    the workspace only
  B  optional  A + released agent-edit and agent-map installed in tools/route-tools (no guidance anywhere)
  C  guided    B + one AGENTS.md section: where the binaries are and the `agent-edit instructions --format=text` output

The workspace is a fresh single-commit repository without remote or history, committed with a fixed identity and
date, so its baseline SHA is the same in every arm. Oracles never enter it.
"""
import argparse
import hashlib
import json
import os
import shutil
import subprocess
import sys
from pathlib import Path

KIT = Path(__file__).resolve().parent
REPO_ROOT = KIT.parent.parent
FIXED_ENV = {
    'GIT_AUTHOR_NAME': 'route-experiment', 'GIT_AUTHOR_EMAIL': 'route-experiment@example.invalid',
    'GIT_COMMITTER_NAME': 'route-experiment', 'GIT_COMMITTER_EMAIL': 'route-experiment@example.invalid',
    'GIT_AUTHOR_DATE': '2026-01-01T00:00:00Z', 'GIT_COMMITTER_DATE': '2026-01-01T00:00:00Z',
}


def run(cmd, cwd, env=None, check=True):
    return subprocess.run(cmd, cwd=cwd, env={**os.environ, **(env or {})}, check=check, text=True, capture_output=True)


def sha256(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def tree_digest(root: Path) -> str:
    digest = hashlib.sha256()
    for path in sorted(p for p in root.rglob('*') if p.is_file() and '.git' not in p.relative_to(root).parts):
        digest.update(str(path.relative_to(root)).encode() + b'\0' + path.read_bytes() + b'\0')
    return digest.hexdigest()


def materialize(case: dict, repo: Path, source: Path | None) -> None:
    kind = case['source']['type']
    if kind == 'benchmark_fixture':
        run(['php', str(REPO_ROOT / 'tools/route-benchmark.php'), f'--fixture={repo}'], REPO_ROOT)
    elif kind == 'git':
        if source is None:
            sys.exit(f"case {case['id']} needs --source: a local clone of {case['source']['repository']}")
        archive = subprocess.run(['git', '-C', str(source), 'archive', '--format=tar', case['source']['commit']], check=True, capture_output=True).stdout
        subprocess.run(['tar', '-x', '-C', str(repo)], input=archive, check=True)
    else:
        sys.exit(f'unknown source type {kind}')


def install_tools(target: Path, versions: dict, composer: bool) -> dict:
    """An isolated Composer project with the released tools; nothing in the workspace's own composer.json changes."""
    target.mkdir(parents=True)
    (target / 'composer.json').write_text(json.dumps({
        'require': {
            'voku/agent-edit': versions['agent_edit_version'],
            'voku/agent-map': versions['agent_map_version'],
            'phpstan/phpstan': '^2.1',
        },
        'config': {'sort-packages': True},
    }, indent=2) + '\n')
    if composer:
        run(['composer', 'install', '--no-interaction', '--no-progress', '--prefer-dist'], target)
    return {'agent_edit': f"tools/route-tools/vendor/bin/agent-edit", 'agent_map': 'tools/route-tools/vendor/bin/agent-map', 'installed': composer}


def guidance(tools: dict, repo: Path) -> str:
    """The minimal route guidance of arm C: binary locations plus the registry-backed instruction text."""
    text = ''
    if tools['installed']:
        text = run([str(repo / tools['agent_edit']), 'instructions', '--format=text'], repo).stdout
    return (
        '## Deterministic editing tools (optional)\n\n'
        f"`{tools['agent_edit']}` and `{tools['agent_map']}` are installed. Use them when they fit the change; "
        'native editing stays available for everything else.\n\n' + text
    )


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument('--case', required=True)
    parser.add_argument('--arm', required=True, choices=['A', 'B', 'C'])
    parser.add_argument('--out', required=True)
    parser.add_argument('--source', help='local clone for git cases (outside the workspace)')
    parser.add_argument('--no-composer', action='store_true', help='skip Composer installs (dry preparation only)')
    args = parser.parse_args()

    cases = json.loads((KIT / 'cases.json').read_text())
    case = next((c for c in cases['cases'] if c['id'] == args.case), None)
    if case is None:
        sys.exit(f'unknown case {args.case}')
    out = Path(args.out).resolve()
    if out.exists():
        sys.exit(f'{out} exists; every arm gets a new directory')
    repo = out / 'repo'
    repo.mkdir(parents=True)

    materialize(case, repo, Path(args.source).resolve() if args.source else None)
    if case.get('test_command') and not args.no_composer and (repo / 'composer.json').exists():
        run(['composer', 'install', '--no-interaction', '--no-progress', '--prefer-dist'], repo)
    (repo / '.gitignore').write_text(((repo / '.gitignore').read_text() if (repo / '.gitignore').exists() else '') + '\nvendor/\ntools/route-tools/vendor/\n.agent-map/\n.agent-edit/\n')
    baseline_digest = tree_digest(repo)

    tools = None
    instruction_bytes = 0
    if args.arm in ('B', 'C'):
        tools = install_tools(repo / 'tools/route-tools', cases, not args.no_composer)
    if args.arm == 'C':
        section = guidance(tools, repo)
        agents = repo / 'AGENTS.md'
        agents.write_text((agents.read_text() + '\n\n' if agents.exists() else '') + section)
        instruction_bytes = len(section.encode())

    run(['git', 'init', '-q', '.'], repo)
    run(['git', 'add', '-A'], repo)
    run(['git', 'commit', '-qm', 'baseline'], repo, FIXED_ENV)
    task = (KIT / case['task']).read_text()
    (out / 'task.md').write_text(task)
    manifest = {
        'schema_version': '1.0',
        'kind': 'route_experiment_arm',
        'case': case['id'],
        'case_kind': case['kind'],
        'arm': args.arm,
        'expected_route': case['expected_route'],
        'task_sha256': hashlib.sha256(task.encode()).hexdigest(),
        'case_source_digest': baseline_digest,
        'workspace_commit': run(['git', 'rev-parse', 'HEAD'], repo).stdout.strip(),
        'tools': tools,
        'instruction_overhead_bytes': instruction_bytes,
        'composer_installed': not args.no_composer,
        'kit_sha256': {name: sha256(KIT / name) for name in ['cases.json', 'prepare.py', 'grade.py', case['oracle']]},
    }
    (out / 'manifest.json').write_text(json.dumps(manifest, indent=2) + '\n')
    # The baseline tree for grading lives next to the workspace, outside it.
    shutil.copytree(repo, out / 'baseline', ignore=shutil.ignore_patterns('.git', 'vendor'))
    print(json.dumps({k: manifest[k] for k in ['case', 'arm', 'workspace_commit', 'case_source_digest', 'instruction_overhead_bytes']}))


if __name__ == '__main__':
    main()
