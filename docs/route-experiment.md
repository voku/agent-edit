# Route experiment: do real agents gain anything from deterministic editing?

Status: **Run 1 done** (12 in-session runs, one repetition per cell; see Results). It hit a ceiling effect: every
arm was correct on every case, so it shows tool selection and overhead, not an effectiveness difference.

## Question

The [route benchmark](../README.md#route-benchmark) measures the mechanical part of each editing route. It shows
where Map + Edit needs fewer bytes and calls, and where native editing is cheaper. It cannot say whether a real
coding agent is more correct, cheaper or faster with these tools, or whether it picks the right route unprompted.
This experiment asks exactly that. The routing table below is a **hypothesis to test**, not a policy:

| Operation | Hypothesised best route |
| --- | --- |
| Business logic, new implementation, local bug fix | native |
| Exact declaration removal, guarded anchored mutation | direct |
| Cross-file rename, symbol relocation, dependency-aware refactoring | Map + Edit |
| Verification | every route |

No mandatory routing, new runtime abstraction or permanent prompt instruction follows from this document until the
results warrant it. A result where agents choose well without guidance argues against building routing at all.

## Arms

The task text is identical in every arm, and so is the case source tree (`case_source_digest` in each manifest).

| Arm | Workspace (`tools/route-experiment/prepare.py`) |
| --- | --- |
| **A** native | The case tree only: a fresh single-commit repository with no remote and no history. |
| **B** optional | A, plus released `voku/agent-edit` and `voku/agent-map` (exact versions in `cases.json`) in an isolated `tools/route-tools` Composer project. Nothing mentions them. |
| **C** guided | B, plus one `AGENTS.md` section naming the two binaries and containing `agent-edit instructions --format=text`, about 3 KB. Its size is recorded as `instruction_overhead_bytes`. |

The workspace layout (`manifest.json`, `task.md`, `repo/`) is the one agent-loop's
[`tools/learning-effect/run-arm.sh`](https://github.com/voku/agent-loop/blob/main/tools/learning-effect/run-arm.sh)
runs. That script is reused unchanged, with the same frozen envelope, Bash network sandbox and probe, and the same
rule against deleting runs. agent-edit itself makes no model calls.

## Cases

| Case | Kind | What makes it hard |
| --- | --- | --- |
| `fixture-rename` | adversarial | Rename `Invoice::total()` while `Cart::total()` is called on the same line; a SQL string, an array key and a comment also contain `total`. |
| `fixture-remove` | adversarial | Remove an unused private method and leave everything else byte-equivalent in code. |
| `fixture-body` | local implementation | A business-logic change (ignore negative lines) in one method. Native editing is expected to win. |
| `recall-rename` | real code | Rename the public `SelectionResult::toRecallResult()` in voku/agent-recall-compiler at `ad580ac`. It has 4 callers in 4 files, and the full PHPUnit suite must stay green. |

The fixture is emitted by `tools/route-benchmark.php --fixture=DIR`, so the experiment and the benchmark use the
same files.

**Historical refactorings.** A scan of agent-loop (2,810 commits), agent-map and agent-edit did not find a commit
that is a pure mechanical rename. The scan looked for commits where one method or call name disappears and another
appears in two or more files. Renames in these repositories always came bundled with behaviour changes, such as
agent-loop `01edde10f5`, which merges `RelativePath::fromRoot()` into `PathResolver::relativeTo()` with new
separator normalisation. A historical commit therefore cannot serve as a clean oracle, so the real-code case is a
designed task on a real code base. More cases are added through `cases.json` with their own oracle.

## Oracles and isolation

Oracles live in `tools/route-experiment/oracles/` and never enter a workspace. They check behaviour by executing the
final tree, check identity (old name gone, new name declared, unrelated symbols untouched), and for `recall-rename`
run the suite. Source changes beyond the rename are reported as unintended changes, not counted as incorrect. The
isolation rules of the agent-loop experiments apply unchanged:

- Run each arm in a disposable container that holds only its `<out>` directory; Read, Glob and Grep can read the
  whole disk.
- Grade only in a disposable environment, because grading executes candidate-written code.

## Measures (`tools/route-experiment/grade.py`)

| Measure | Definition |
| --- | --- |
| first-pass correctness | Oracle (and suite, where a case has one) passes on the final tree. |
| unintended changes | Changed paths outside the case's `allowed_paths`; tool state and installs excluded. |
| chosen route | From the transcript: Bash calls of `agent-map` and `agent-edit`, and Edit/Write calls. Reported as `map+edit`, `direct`, `native` or `none`. Measured in every arm, so B shows unprompted selection. |
| retries | Failed tool results (`is_error`). |
| tokens, turns, duration, cost | From the stream-json `result` event; `null` when the host does not report them. Input tokens include cache reads and writes. |
| instruction overhead | Bytes of the arm C guidance section. |
| cost per success | Cost of a run that is correct; failed runs are kept in the ledger. |

Comparisons fixed before any run:

1. **B vs A** on every case: does merely installing the tools change correctness or cost, and which route does an
   agent choose unprompted?
2. **C vs B:** does about 3 KB of guidance pay for its own tokens, and does it move route choice towards the
   hypothesis?
3. **Per case kind:** the adversarial and real-code renames are where Map + Edit is expected to help. On
   `fixture-body`, any arm that does worse than A is a cost of the tooling and is reported as such.

Schedule: 4 cases × 3 arms × 3 repetitions = 36 runs. They run in an order shuffled with a recorded seed, each with a
new session and a new workspace. Negative and failed runs are kept and graded.

## Procedure

```bash
kit=tools/route-experiment
python3 $kit/prepare.py --case fixture-rename --arm B --out runs/fr-B-1
../agent-loop/tools/learning-effect/run-arm.sh runs/fr-B-1 <model-id>
python3 $kit/grade.py --out runs/fr-B-1 --ledger docs/route-experiment.results.jsonl
```

`recall-rename` needs `--source` pointing at a clone of voku/agent-recall-compiler that contains `ad580ac`.

**In-session runs without Composer access** (as in Run 1) replace the Composer installs with one prepared runtime:

```bash
python3 $kit/prepare.py --case fixture-rename --arm C --out runs/fixture-rename-C --no-composer --tools-runtime RUNTIME
python3 $kit/prepare.py --case recall-rename --arm A --out runs/recall-rename-A --no-composer --source ../agent-recall-compiler \
  --vendor-shim RUNTIME --extra-psr4 'voku\AgentLearning\=PATH/agent-learning/src/'
```

`RUNTIME` is an agent-edit checkout at the pinned release with its `vendor/`. Run each arm with a fresh agent, record
the host's usage report in `<out>/run.json` (`ran`, `total_tokens`, `tool_uses`, `duration_ms`), then grade.

CI runs `python3 tools/route-experiment/calibrate.py` on every change. It prepares arm A of every case and grades
four kinds of simulated outcome, with no model run:

| Outcome | Expected verdict |
| --- | --- |
| untouched workspace | incorrect |
| reference solution | correct |
| reference plus a stray file | one unintended path |
| plausible native shortcut on `fixture-rename` | incorrect, with `src/Cart.php` unintended |

## Results

### Run 1 (2026-11-19, in-session agents, one repetition per cell)

Executed inside a Claude Code session with no API key, following agent-loop's learning-effect Run 1 method. Each run
was a fresh general-purpose agent of the same model as the session, with no memory of the conversation. Every run
got the identical instruction:
- work only in this workspace, with no network;
- follow the repository's agent instructions;
- do not commit;
- report what you changed.

The task text followed. All 12 ran in parallel, and no human intervened. Per-run data:
[`route-experiment.results.jsonl`](route-experiment.results.jsonl).

| Case | A native | B tools, unmentioned | C tools + guidance |
| --- | --- | --- | --- |
| `fixture-rename` | correct, native | correct, native | correct, native |
| `fixture-remove` | correct, native | correct, native | correct, native |
| `fixture-body` | correct, native | correct, native | correct, **direct** (`agent-edit edit` + `verify`) |
| `recall-rename` | correct, native, suite green | correct, native, suite green | correct, native, suite green |

| Arm | Correct | Unintended changes | agent-map / agent-edit calls | Mean tokens | Mean tool calls | Mean seconds |
| --- | --- | --- | --- | ---: | ---: | ---: |
| A | 4/4 | 0 | n/a | 63,931 | 4.25 | 16.5 |
| B | 4/4 | 0 | 0 / 0 | 63,763 | 4.00 | 15.3 |
| C | 4/4 | 0 | 0 / 2 (one run) | 66,039 | 4.00 | 16.7 |

**What this shows:**

- **Ceiling effect.** All 12 agents produced the correct change and avoided every trap: `Cart::total()` called on
  the same line, the SQL string, the array key and the comment. All three `recall-rename` runs kept the suite green
  apart from the one environment failure the baseline already has. No native agent took the replace-everywhere
  shortcut that breaks the code in the route benchmark. With this model and these tasks, the tools cannot improve
  correctness, so this run cannot show an effectiveness gain in either direction.
- **Unprompted, the tools go unused (B: 0/4).** One B agent noticed `tools/route-tools` and still edited by hand.
  Installing the tools alone changes nothing.
- **With guidance, the tools are used where they help least.** The only use in arm C was a direct
  `agent-edit edit` (plus `verify`) for the local body change, the case where the route benchmark shows native
  editing is cheaper. No agent used agent-map, not even for the two renames the hypothesis assigns to Map + Edit. One
  C agent said the removal plan needs PHPStan; the runtime has PHPStan installed, so this was the agent's own
  assumption, not a missing capability.
- **Guidance costs tokens without a quality gain here.** C used about 3% more tokens than A, close to the 3.2 KB of
  guidance it reads. That difference is small next to the roughly 62k tokens of fixed agent overhead in every run.
  With n=1 per cell, the differences in tokens, tool calls and time are noise.
- **For the routing hypothesis:** this run gives no evidence for adding routing or more guidance, and some against
  it. The one guided choice went against the hypothesis. Whether Map + Edit pays off is untested, because no agent
  needed it here.

**Confounds and deviations, all recorded:**

- **Separation.** All agents shared one machine and were confined to their workspace by instruction only, with no
  sandbox. The shared tool runtime (agent-edit 0.8.0 with agent-map 0.24.0 in its vendor directory) hashed
  identically before and after the runs.
- **Tools without Composer.** Composer could not download here. Arms B and C got logging wrappers around one prepared
  runtime (`prepare.py --tools-runtime`), and the route is read from those logs (`tool-calls.log`, outside the
  workspace), not from the agent's own account. Preparing arm C first logged its own `instructions` call into those
  logs. Those preparation lines were removed before grading, and `prepare.py` now calls the runtime directly for
  that step.
- **Recall suite.** The workspace got a vendor shim (`--vendor-shim`) pointing at the same runtime plus agent-learning
  0.19.0. The suite runs; the one baseline failure (`AgentLearningNoteProjectionSourceTest`) comes from this
  environment and is excluded through the recorded baseline failures. PHPStan was not available in the Recall
  workspace, and two agents noted that. One agent needed `COMPOSER_ALLOW_SUPERUSER=1` to start `composer ci`.
- **Usage numbers.** Tokens, tool calls and duration come from the host's per-agent usage report, recorded by the
  operator in each `run.json`. They are not from a stream-json transcript.
- **Model.** The agents are the same model as the operating session, and the tasks may be familiar from training.
- **One extra change.** `recall-rename` A added a CHANGELOG entry for the public rename. That path is allowed, so it
  does not count as unintended.

**Next useful experiment.** Like the pipefail run, this one can only show an effect on tasks where native editing
fails. Candidates are:
- a rename with many call sites across many files;
- same-named methods across a class hierarchy;
- dynamic or `callable` references;
- a stale read, where the file changes between reading and editing.

Until such a task exists, more runs on these cases measure only overhead.

### Calibration

CI runs `calibrate.py` on every change. Calibration success is not an experiment result.

## Limitations

- Four cases, three of them on one small fixture. They show where the routes differ, not how often each situation
  occurs in real work.
- Arm B measures unprompted discovery of tools that nothing mentions. A host that announces its tools differently
  sits between B and C.
- The model may know refactoring idioms or these packages from training; a strong arm A is informative, not a
  setup failure.
- The real-code case is a designed rename on a real code base, not a replayed historical change (see above).
