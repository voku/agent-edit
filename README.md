# agent-edit

Deterministic, evidence-backed source mutation for coding agents.

`agent-edit` is the **write plane** next to [`agent-map`](https://github.com/voku/agent-map) (the read/planning plane):

```text
agent-map   = observe + plan
agent-edit  = validate + mutate + verify
agent-loop  = authorize + orchestrate
```

It consumes one already-produced, versioned `agent-map` plan, revalidates every source hash, inclusive byte range, expected token and plan provenance against the *current* source and Map, stages and syntax-checks all rewritten PHP, publishes edits and file moves in one transaction (every file is restored on any failure), and then verifies the result against a fresh Map.

It is **not** an LLM editor, not a workflow engine and not a refactoring recommender. It never calls a model: a quick local fix is authored by the caller as a `source_edit_plan` (see [Direct edits](#direct-edits-quick-fixes-without-a-map)) and proven against the current source like any other plan.

## Use

```bash
vendor/bin/agent-map build --root=. --paths=src --out=.agent-map/php-symbols.json
vendor/bin/agent-map method-move-plan 'App\Foo::helper' 'App\Bar' --format=json > plan.json

vendor/bin/agent-edit apply plan.json --dry-run     # validate everything, write nothing
vendor/bin/agent-edit apply plan.json               # transactional apply + receipt bundle

vendor/bin/agent-map build --root=. --paths=src --out=.agent-map/php-symbols.json   # refresh the map
vendor/bin/agent-edit verify --bundle=.agent-edit/receipts/<label>
```

`apply` writes `execution.json` (the receipt) into `--output-dir` (default `.agent-edit/receipts/<label>`; `--task LABEL` sets the label, default `plan-<sha256 prefix>`). `verify` re-reads that receipt, the bound plan and the refreshed Map and writes `verification-result.json`. Each verification attempt first removes the previous result, so a failed attempt cannot leave an earlier `passed` verdict in the bundle.

## Direct edits (quick fixes without a Map)

A Map plan earns its keep when a change reaches *other* files: renaming a method, moving a class or deleting a symbol needs proven callers, which is what `agent-map` computes and `agent-edit` revalidates. A quick local fix (a wrong operator, a missing null check, a README sentence, a new test file) reaches no other file, so demanding a Map for it only adds a step. For those, the caller authors a `source_edit_plan@1.0`: the exact text it just read and the exact text it wants instead. The plan is still evidence, never authority: agent-edit proves every claim against the current bytes before it writes.

```bash
vendor/bin/agent-edit edit src/Calc.php --find='return $a - $b;' --replace='return $a + $b;'   # one edit
vendor/bin/agent-edit apply - <<'JSON'                                                             # several edits / new files
{"type":"source_edit_plan","contract_version":"1.0","target_id":"fix-add",
 "edits":[{"path":"src/Calc.php","find":"return $a - $b;","replace":"return $a + $b;"}],
 "creates":[{"path":"tests/CalcTest.php","content":"<?php\n..."}]}
JSON
vendor/bin/agent-edit verify --bundle=.agent-edit/receipts/<label>     # no Map needed
vendor/bin/agent-edit undo   --bundle=.agent-edit/receipts/<label>     # restore the recorded pre-images
vendor/bin/agent-edit schema                                           # JSON Schema of the plan
```

What agent-edit proves before anything is written (`--dry-run` stops here):

- **Anchors.** `find` must occur exactly once (or be picked with `occurrence`, or replaced everywhere with `all`). A refusal says what to do next: the line numbers of an ambiguous match, the line where the same text exists with different whitespace or line endings, or the closest line when the text differs. Optional `expected_source_sha256` refuses a file that changed since it was read.
- **Syntax.** Every rewritten or created `.php` file is staged and `php -l` checked; publication is all-or-nothing and every file is restored on any failure.
- **API changes need caller evidence.** A token-level guard compares the declarations before and after. Removing or renaming a class, function or non-private method, or changing a signature (parameters, types, defaults, visibility), is refused with a pointer to the matching `agent-map` plan. After checking the callers by other means, `"allow_signature_change": true` (`--allow-signature-change`) applies it and records the acknowledged changes in the receipt. Bodies, private methods, properties and additions are never reported.
- **Scope.** Unknown keys, absolute or `..` paths, `.git/` and `.agent-edit/` targets, binary files, symlinks and paths that leave the project root are refused.

The receipt (`execution.json`) binds the plan hash, Git-observed `changed_files` and, per file, the digest before and after plus a pre-image under `before/`. `verify` re-proves the plan is unchanged, every file still has the published digest, PHP still parses and the observed changed files are exactly the plan's files. `undo` restores the pre-images and removes created files only while every touched file still has the digest the edit published, so later work is never overwritten; an undone bundle no longer verifies. Files Git ignores (local config, generated code) cannot appear in `git status`: they are proven by digest only and listed in `git_ignored_files_proven_by_digest_only`. Without Git the verdict is `incomplete` / `scope_unproven` (exit code 3), as for Map plans.

Direct edits and Map plans are complementary, not interchangeable:

| Change | Use |
| --- | --- |
| Body fix, config/doc text, new file, private-method change | `source_edit_plan` (`edit` / `apply -`) |
| Rename, move or remove a class/method/property/constant | the matching `agent-map` plan, then `apply` |
| Signature change whose callers you checked yourself | `source_edit_plan` with `allow_signature_change` |

## Capabilities

```bash
vendor/bin/agent-edit capabilities --format=json
vendor/bin/agent-map plan-capabilities --format=json | vendor/bin/agent-edit capabilities --with-map=-
vendor/bin/agent-edit instructions --format=json
vendor/bin/agent-map plan-capabilities --format=json | vendor/bin/agent-edit instructions --with-map=-
```

The second command intersects what Map can *plan* with what agent-edit can *execute*: `executable`, `planned_not_executable` (for example `class_scaffold_plan`) and `executable_not_planned`. A host should expose only the intersection to a coding agent.

`instructions` emits prompt-ready JSON with a short operating procedure and the plan commands from the same executable capability registry. Without `--with-map`, it labels the registry as an upper bound; piping current Map capabilities through `--with-map=-` resolves the exact installed intersection. Hosts can include this output in a coding agent's context instead of maintaining a second, drifting list of executable refactors.

The guidance keeps the boundary explicit: Map proposes a versioned plan, agent-edit validates and applies only that plan, and the host handles arbitrary behavioral edits and their semantic review. It does not add model calls or make agent-edit a free-form editor.

`class_removal_plan` deletes exactly one whole file: the plan must be `safe` (no blockers, blind spots or stale evidence), publish no edits or moves, and name the single owned source file with its hash. Apply re-proves the hash, the target's sole declaration and the absence of incoming Map evidence before the file is moved aside, hash-checked and discarded; verify requires the file and class to be gone from a rebuilt Map.

Executable contracts (all `@1.0`): `method|function|class|property|class_constant|parameter_rename_plan`, `class_move_plan`, `method_move_plan`, `method_copy_plan`, `method_scaffold_plan`, `method_removal_plan`, `property_removal_plan`, `class_constant_removal_plan`, `class_removal_plan`, and the caller-authored, Map-free `source_edit_plan` (see [Direct edits](#direct-edits-quick-fixes-without-a-map)).

`method_copy_plan` and `method_scaffold_plan` are executable only when Map publishes a `safe` plan with empty blind-spot, stale and blocker evidence. A copy that refers to `$this`, `self` or `static` across class ownership is `review_required`, so agent-edit refuses it rather than copying behavior it cannot prove. Both contracts preserve Map's exact insertions and need a PHPStan-backed Map; they do not permit a host to invent a method body or patch the plan by hand.

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
$undo = $engine->undo($root, $bundleDir, $hostAuthorize); // optional host hook guards the restore too
```

Plan type and contract version are routed only through `CapabilityRegistry`. Anything it does not list (an unknown type, or a known type with an unknown `contract_version`) is rejected before any source is read.

## Receipt

`execution.json` inside the bundle is an **agent-edit receipt** (`schema_version` 1.0). It binds the plan file hash, Map digest, runner identity and Git-observed `changed_files`, and is what `verify` consumes. It is not owned by `agent-loop`. The names `execution.json`, `task_id` (the caller-supplied label) and `runner.name` are kept for compatibility with hosts that already read them; `model_input_tokens`/`model_tool_calls` are always `0`.

Without Git the receipt falls back to a Map-scoped observation: before mutating, `agent-edit` stores `map-scope-before.json` (path → sha256 for every Map-indexed file, plus the observed absence of preflight-validated move destinations) in the bundle and the receipt references it only by `scope_evidence` (`source`, `path`, `sha256`) with `changed_files_source: map_manifest_diff`. This records both sides of a file move. `verify` recomputes the manifest and diffs it, so an extra changed *indexed* file outside the plan is an error. It proves only `map_indexed_files`: the result is `status: incomplete` with `scope.status: scope_unproven` (never `passed`, CLI exit code `3`), because a file outside the Map index can change unobserved. With Git the receipt and result are unchanged.

### Residue: Markdown and template mentions

The PHP edit of a `method_rename_plan`, `class_rename_plan`, `method_removal_plan` or `class_removal_plan` is proven by the plan; text that merely mentions the old symbol is not. After the plan-type verifier passes, `verify` re-scans Markdown and Twig / Smarty / Blade files for the old symbol (with agent-map's `NonPhpReferenceScanner`) and adds a `residue` block to `verification-result.json`: `status` (`clear`, `open` or `accepted`), `open` and `historical` counts, `truncated`, `scanned_files` and the first non-historical `references` (path, line, byte range, confidence, matched text). Mentions in changelog-style files (`CHANGELOG`, `UPGRADING`, ...) are counted as `historical` and never block.

While non-historical mentions remain, an otherwise `passed` result is `status: incomplete` (CLI exit code `3`), so a governed close cannot pass on top of stale docs. Two ways forward: fix the mentions with a separate cleanup (for example a coding agent working from the listed references) and re-run `verify`, which re-scans and reports `clear`; or accept them with `--accept-residue=REASON` (`$engine->verify(..., acceptResidue: 'REASON')`), which records the reason in the result as `residue.disposition` and leaves the verdict `passed`. The scan matches text, not types, so `member_name_only` hits (an `->oldName(` in a template) can be unrelated; the confidence says how strong each hit is. Other plan types are unchanged.

### Residue: orphaned `use` imports

A removal or move deletes one declaration; the `use` import that only it needed stays behind, and `apply` never adds edits the plan did not carry. After `method_removal_plan`, `method_move_plan`, `property_removal_plan` or `class_constant_removal_plan`, `verify` therefore lists imports in the edited file whose alias occurred in the deleted text and is no longer referenced (code, comments or docblocks) in an `import_residue` block. While `open`, an otherwise `passed` result is `incomplete` (exit code 3); remove the imports and re-run `verify`, or accept with `--accept-residue=REASON`. Grouped, `function` and `const` imports are not judged.

After an authorized mutation attempt fails, `applyWithReceipt()` first observes the post-rollback working tree and persists a `runner_failed` receipt before rethrowing the original failure. A host authorization refusal still happens before the mutation attempt and writes no receipt.

A refusal that writes no receipt (dry-run preflight refusal such as a `blocked` plan, an unsupported plan type or contract version, a host authorization refusal) leaves **no bundle directory behind**: directories `applyWithReceipt()` created for the attempt are removed again if they are still empty; a bundle directory that already existed, or that holds any file, is never touched. An authorized mutation attempt that fails keeps its `runner_failed` receipt.

**When the receipt itself cannot be written** (unwritable bundle directory, full disk, a blocking path), the apply outcome stays the primary signal and nothing is silent:

| Situation | Behavior |
| --- | --- |
| Source published, receipt write fails | `ReceiptNotPersistedException` (`changedFiles`, `outputDirectory`, previous = the write error). The lock is already released and the transaction committed, so there is no snapshot to restore: the working tree is changed without evidence. No receipt file exists (never a half-written one), so `verify` refuses the bundle and a governed close cannot pass. Restore from version control and re-plan. |
| Apply failed (rolled back), failure receipt write fails | The original apply failure is rethrown unchanged; the receipt problem never replaces it. |
| Dry run, receipt write fails | The plain write error; nothing was published. |

## Boundaries

`agent-edit` owns plan validation, exact edits/moves, transactional publication, rollback, the mutation lock, observed changed files, receipts and deterministic verification, and the executable capability list.

It does not own repository analysis or plan generation (`agent-map`), task approval/workflow state, LLM routing, briefing, durable task evidence or closeout (`agent-loop`), and it never commits or pushes.

## Validation

```bash
composer ci
```

PHPStan-backed plans need `phpstan/phpstan` installed so Map can build a `+phpstan` index.
