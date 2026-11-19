# Route experiment: do real agents gain anything from deterministic editing?

Status: **prepared, not run.** No model run exists yet; every effectiveness, token and tool-selection result below is
UNKNOWN. Calibration success is not an experiment result.

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

CI runs `python3 tools/route-experiment/calibrate.py` on every change. It prepares arm A of every case and grades
four kinds of simulated outcome, with no model run:

| Outcome | Expected verdict |
| --- | --- |
| untouched workspace | incorrect |
| reference solution | correct |
| reference plus a stray file | one unintended path |
| plausible native shortcut on `fixture-rename` | incorrect, with `src/Cart.php` unintended |

## Results

| Case | A | B | C |
| --- | --- | --- | --- |
| `fixture-rename` | NOT_RUN | NOT_RUN | NOT_RUN |
| `fixture-remove` | NOT_RUN | NOT_RUN | NOT_RUN |
| `fixture-body` | NOT_RUN | NOT_RUN | NOT_RUN |
| `recall-rename` | NOT_RUN | NOT_RUN | NOT_RUN |

### Execution blockers (2026-11-19, Claude Code cloud session)

These are the same as for the agent-loop learning-effect experiment:

- **No model credential.** There is no credential for a nested headless `claude` session.
- **No Composer GitHub access.** Composer cannot download from GitHub, so arms B and C, and the `recall-rename`
  suite, cannot be installed here.

Validated locally:

- **Calibration:** every expected verdict above, without the suite.
- **Arm preparation:** A, B and C have the same case source digest. C differs from B only by `AGENTS.md`.
- **Grading of a synthetic transcript:** route detection, failed tool results, token totals and instruction
  overhead are read correctly.

## Limitations

- Four cases, three of them on one small fixture. They show where the routes differ, not how often each situation
  occurs in real work.
- Arm B measures unprompted discovery of tools that nothing mentions. A host that announces its tools differently
  sits between B and C.
- The model may know refactoring idioms or these packages from training; a strong arm A is informative, not a
  setup failure.
- The real-code case is a designed rename on a real code base, not a replayed historical change (see above).
