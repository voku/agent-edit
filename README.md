# agent-edit

Deterministic, evidence-backed source mutation for coding agents.

`agent-edit` is the **write plane** next to [`agent-map`](https://github.com/voku/agent-map) (the read/planning plane):

```text
agent-map   = observe + plan
agent-edit  = validate + mutate + verify
agent-loop  = authorize + orchestrate
```

It consumes one already-produced, versioned `agent-map` plan, revalidates every source hash, inclusive byte range, expected token and plan provenance against the *current* source and Map, stages and syntax-checks all rewritten PHP, publishes edits and file moves in one transaction (every file is restored on any failure), and then verifies the result against a fresh Map.

It is **not** an LLM editor, not a workflow engine and not a refactoring recommender.

## Use

```bash
vendor/bin/agent-map build --root=. --paths=src --out=.agent-map/php-symbols.json
vendor/bin/agent-map method-move-plan 'App\Foo::helper' 'App\Bar' --format=json > plan.json

vendor/bin/agent-edit apply plan.json --dry-run     # validate everything, write nothing
vendor/bin/agent-edit apply plan.json               # transactional apply + receipt bundle

vendor/bin/agent-map build --root=. --paths=src --out=.agent-map/php-symbols.json   # refresh the map
vendor/bin/agent-edit verify --bundle=.agent-edit/receipts/<label>
```

`apply` writes `execution.json` (the receipt) into `--output-dir` (default `.agent-edit/receipts/<label>`; `--task LABEL` sets the label, default `plan-<sha256 prefix>`). `verify` re-reads that receipt, the bound plan and the refreshed Map and writes `verification-result.json`.

## Capabilities

```bash
vendor/bin/agent-edit capabilities --format=json
vendor/bin/agent-map plan-capabilities --format=json | vendor/bin/agent-edit capabilities --with-map=-
```

The second command intersects what Map can *plan* with what agent-edit can *execute*: `executable`, `planned_not_executable` (for example `method_copy_plan`) and `executable_not_planned`. A host should expose only the intersection to a coding agent.

Executable contracts (all `@1.0`): `method|function|class|property|class_constant|parameter_rename_plan`, `class_move_plan`, `method_move_plan`, `method_removal_plan`, `property_removal_plan`, `class_constant_removal_plan`.

## Package API

The package API is the authority; the CLI wraps it.

```php
$engine = new voku\AgentEdit\EditEngine();
$engine->preflight($plan, $map, $root);   // validate only
$result = $engine->apply($plan, $map, $root); // lock + transactional apply
```

`voku\AgentEdit\Cli\ApplyCommand` accepts a `$beforeMutation` closure so a host (for example `agent-loop`) can run its own authorization before any non-dry-run write; the closure must throw to refuse.

## Boundaries

`agent-edit` owns plan validation, exact edits/moves, transactional publication, rollback, the mutation lock, observed changed files, receipts and deterministic verification, and the executable capability list.

It does not own repository analysis or plan generation (`agent-map`), task approval/workflow state, LLM routing, briefing, durable task evidence or closeout (`agent-loop`), and it never commits or pushes.

## Validation

```bash
composer ci
```

PHPStan-backed plans need `phpstan/phpstan` installed so Map can build a `+phpstan` index.
