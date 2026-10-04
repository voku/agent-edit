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

`voku\AgentEdit\EditEngine` is the only authority; `agent-edit apply|verify|capabilities` are argument/print adapters over it.

```php
$engine = new voku\AgentEdit\EditEngine();

$engine->preflight($plan, $map, $root);          // validate everything, write nothing
$engine->apply($plan, $map, $root);              // mutation lock + transactional apply (no receipt)

$receipt = $engine->applyWithReceipt(new ApplyRequest(
    repositoryRoot: $root, planPath: $planFile, mapIndexPath: $mapFile, mapRoot: $root,
    outputDirectory: $bundleDir, label: 'my-task', dryRun: false,
    authorizeMutation: static fn (string $label) => $host->assertMayMutate($label), // must throw to refuse
));

$result = $engine->verify($root, $bundleDir, $mapFile); // after rebuilding the Map; writes verification-result.json
```

Plan type and contract version are routed only through `CapabilityRegistry`. Anything it does not list (an unknown type, or a known type with an unknown `contract_version`) is rejected before any source is read.

## Receipt

`execution.json` inside the bundle is an **agent-edit receipt** (`schema_version` 1.0). It binds the plan file hash, Map digest, runner identity and Git-observed `changed_files`, and is what `verify` consumes. It is not owned by `agent-loop`. The names `execution.json`, `task_id` (the caller-supplied label) and `runner.name` are kept for compatibility with hosts that already read them; `model_input_tokens`/`model_tool_calls` are always `0`.

Without Git the receipt falls back to a Map-scoped observation: before mutating, `agent-edit` stores `map-scope-before.json` (path → sha256 for every Map-indexed file) in the bundle and the receipt references it only by `scope_evidence` (`source`, `path`, `sha256`) with `changed_files_source: map_manifest_diff`. `verify` recomputes the manifest and diffs it, so an extra changed *indexed* file outside the plan is an error. It proves only `map_indexed_files`: the result is `status: incomplete` with `scope.status: scope_unproven` (never `passed`, CLI exit code `3`), because a file outside the Map index can change unobserved. With Git the receipt and result are unchanged.

After an authorized mutation attempt fails, `applyWithReceipt()` first observes the post-rollback working tree and persists a `runner_failed` receipt before rethrowing the original failure. A host authorization refusal still happens before the mutation attempt and writes no receipt.

## Boundaries

`agent-edit` owns plan validation, exact edits/moves, transactional publication, rollback, the mutation lock, observed changed files, receipts and deterministic verification, and the executable capability list.

It does not own repository analysis or plan generation (`agent-map`), task approval/workflow state, LLM routing, briefing, durable task evidence or closeout (`agent-loop`), and it never commits or pushes.

## Validation

```bash
composer ci
```

PHPStan-backed plans need `phpstan/phpstan` installed so Map can build a `+phpstan` index.
