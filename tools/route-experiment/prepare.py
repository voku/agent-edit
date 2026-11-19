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
import re
import shutil
import subprocess
import sys
import tempfile
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
    elif kind == 'generator':
        run(['python3', str(KIT / case['source']['script']), str(repo), 'baseline'], REPO_ROOT)
    elif kind == 'git':
        if source is None:
            sys.exit(f"case {case['id']} needs --source: a local clone of {case['source']['repository']}")
        # Every tracked file, including export-ignored tests and PHPUnit config (git archive would drop them), through
        # a temporary index so the source clone's own index and work tree stay untouched.
        with tempfile.TemporaryDirectory() as scratch:
            # Inherited repository-location variables would override -C and point git at another repository.
            scrub = {'GIT_DIR', 'GIT_WORK_TREE', 'GIT_INDEX_FILE', 'GIT_OBJECT_DIRECTORY', 'GIT_ALTERNATE_OBJECT_DIRECTORIES', 'GIT_COMMON_DIR', 'GIT_NAMESPACE'}
            env = {key: value for key, value in os.environ.items() if key not in scrub}
            env['GIT_INDEX_FILE'] = str(Path(scratch) / 'index')
            subprocess.run(['git', '-C', str(source), 'read-tree', case['source']['commit']], env=env, check=True)
            subprocess.run(['git', '-C', str(source), 'checkout-index', '-a', f'--prefix={repo}/'], env=env, check=True)
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


def install_runtime_wrappers(target: Path, runtime: Path, log: Path) -> dict:
    """In-session runs without Composer access: the binaries are wrappers around one prepared runtime (agent-edit at
    the pinned release with its vendor directory) that log every call to <out>/tool-calls.log, outside the workspace,
    so the route an agent took is read from the log rather than from its own account."""
    bindir = target / 'vendor/bin'
    bindir.mkdir(parents=True)
    binaries = {'agent-edit': runtime / 'bin/agent-edit', 'agent-map': runtime / 'vendor/bin/agent-map'}
    for name, binary in binaries.items():
        if not binary.exists():
            sys.exit(f'runtime has no {binary}')
        wrapper = bindir / name
        wrapper.write_text(
            '#!/usr/bin/env bash\n'
            f'printf \'%s\\t%s\\t%s\\n\' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" {name} "$*" >> {log}\n'
            f'exec php {binary} "$@"\n'
        )
        wrapper.chmod(0o755)
    return {'agent_edit': 'tools/route-tools/vendor/bin/agent-edit', 'agent_map': 'tools/route-tools/vendor/bin/agent-map', 'installed': True, 'runtime': str(runtime)}


def vendor_shim(repo: Path, runtime: Path, extra_psr4: list[str]) -> None:
    """A workspace vendor/ for git cases when Composer cannot install: autoload.php loads the runtime's autoloader and
    the workspace's own PSR-4 roots (plus extra NS=DIR roots); vendor/bin/phpunit and vendor/bin/phpstan run the runtime's."""
    composer = json.loads((repo / 'composer.json').read_text())
    roots = {}
    for section in ('autoload', 'autoload-dev'):
        for namespace, directory in (composer.get(section, {}).get('psr-4') or {}).items():
            roots[namespace] = str(repo / directory)
    for item in extra_psr4:
        namespace, directory = item.split('=', 1)
        roots[namespace] = directory
    (repo / 'vendor/bin').mkdir(parents=True)
    (repo / 'vendor/autoload.php').write_text(
        '<?php\n'
        f'require {json.dumps(str(runtime / "vendor/autoload.php"))};\n'
        f'$roots = json_decode({json.dumps(json.dumps(roots))}, true);\n'
        'spl_autoload_register(static function (string $class) use ($roots): void {\n'
        '    foreach ($roots as $prefix => $dir) {\n'
        '        if (str_starts_with($class, $prefix)) {\n'
        "            $file = rtrim($dir, '/') . '/' . str_replace('\\\\', '/', substr($class, strlen($prefix))) . '.php';\n"
        '            if (is_file($file)) { require $file; return; }\n'
        '        }\n'
        '    }\n'
        '}, true, true);\n'
    )
    # The case's own quality gates: PHPUnit and PHPStan from the runtime, as `composer ci` would call them.
    for tool in ('phpunit', 'phpstan'):
        shim = repo / 'vendor/bin' / tool
        shim.write_text(f'#!/usr/bin/env bash\nexec php {runtime / "vendor/bin" / tool} "$@"\n')
        shim.chmod(0o755)


def test_failures(repo: Path, command: str) -> list[str]:
    """Names of the failing tests of the baseline suite run. Preparation fails when the suite did not complete
    (no PHPUnit summary), so a crashed baseline cannot be recorded as "no failures"."""
    run_result = subprocess.run(command, cwd=repo, shell=True, capture_output=True, text=True)
    output = run_result.stdout
    if re.search(r'^(OK \(|OK, but|Tests: )', output, re.M) is None:
        sys.exit(f'baseline suite did not complete (exit {run_result.returncode}):\n{output[-1500:]}\n{run_result.stderr[-1500:]}')
    failures = sorted({line.split(') ', 1)[1].split(' with data set')[0].strip() for line in output.splitlines() if line[:1].isdigit() and ') ' in line[:6]})
    if run_result.returncode != 0 and not failures:
        sys.exit(f'baseline suite exited {run_result.returncode} without a parsed failure')
    return failures


def guidance(tools: dict, repo: Path) -> str:
    """The minimal route guidance of arm C: binary locations plus the registry-backed instruction text."""
    text = ''
    if tools['installed']:
        # Call the runtime itself, not the logging wrapper: preparation must not show up as an agent's tool call.
        binary = Path(tools['runtime']) / 'bin/agent-edit' if tools.get('runtime') else repo / tools['agent_edit']
        command = ['php', str(binary)] if tools.get('runtime') else [str(binary)]
        text = run(command + ['instructions', '--format=text'], repo).stdout
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
    parser.add_argument('--tools-runtime', help='in-session runs: wrap this prepared agent-edit runtime instead of installing (logs every call)')
    parser.add_argument('--vendor-shim', help='in-session runs: give a git case a vendor/ from this runtime instead of composer install')
    parser.add_argument('--extra-psr4', action='append', default=[], help='NS=DIR roots for --vendor-shim (repeatable)')
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
    baseline_digest = tree_digest(repo)
    baseline_failures = None
    if case.get('test_command') and args.vendor_shim:
        vendor_shim(repo, Path(args.vendor_shim).resolve(), args.extra_psr4)
        baseline_failures = test_failures(repo, case['test_command'])
    elif case.get('test_command') and not args.no_composer and (repo / 'composer.json').exists():
        run(['composer', 'install', '--no-interaction', '--no-progress', '--prefer-dist'], repo)
    (repo / '.gitignore').write_text(((repo / '.gitignore').read_text() if (repo / '.gitignore').exists() else '') + '\nvendor/\ntools/route-tools/vendor/\n.agent-map/\n.agent-edit/\n')

    tools = None
    instruction_bytes = 0
    if args.arm in ('B', 'C') and args.tools_runtime:
        tools = install_runtime_wrappers(repo / 'tools/route-tools', Path(args.tools_runtime).resolve(), out / 'tool-calls.log')
    elif args.arm in ('B', 'C'):
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
        'baseline_test_failures': baseline_failures,
        'kit_sha256': {name: sha256(KIT / name) for name in ['cases.json', 'prepare.py', 'grade.py', case['oracle']]},
    }
    (out / 'manifest.json').write_text(json.dumps(manifest, indent=2) + '\n')
    # The baseline tree for grading lives next to the workspace, outside it.
    shutil.copytree(repo, out / 'baseline', ignore=shutil.ignore_patterns('.git', 'vendor'))
    print(json.dumps({k: manifest[k] for k in ['case', 'arm', 'workspace_commit', 'case_source_digest', 'instruction_overhead_bytes']}))


if __name__ == '__main__':
    main()
