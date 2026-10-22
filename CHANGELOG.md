# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## Unreleased

### Fixed

- `method_copy_plan` and `method_scaffold_plan` refused a `use` import written with CRLF ("must insert exactly one plain `use` statement"), although a destination file with CRLF line endings needs exactly that: the check, and the import-slot check, now accept `\r\n` as the line break (a stray or doubled break is still refused). Needed by an agent-map whose planners follow the destination's line ending instead of leaving a CRLF file with bare LF breaks.
- `agent-edit apply --allow-signature-change` (or any unknown option given without a value) reported "Missing value for option" instead of the unknown option. Unknown options are now named first, and for `--allow-signature-change` the message says to set `"allow_signature_change": true` in the plan.
- Several `remove_symbol` edits of neighbouring declarations in one `source_edit_plan` failed with "Two edits overlap": each removal also takes the blank line it makes redundant, and for neighbours that is the same blank line. Removals that overlap or are separated by whitespace only are now applied one after the other and replaced by the one range that turns the file into that result, so a plan gives exactly the file that one plan per symbol gives (property-tested over every subset of the members of two class layouts, with no doubled blank line and none inside the braces). Removals around an untouched or separately edited member are unaffected.
- Symbol edits wrote the agent's own nesting into the file unchanged, so code indented with four spaces landed in a tab-indented file as `\t    return 1;` (tabs and spaces mixed on one line), and a heredoc in it could not be published at all (PHP refuses a marker indented with both). Re-indentation now writes the code's nesting levels in the target file's indentation (tabs, or its space width), also for the closing-marker prefix of a heredoc; alignment spaces beyond whole levels, comment continuation lines, string and heredoc content are kept. Nested heredocs keep their prefix as written.
- Symbol edits (`replace_symbol`, `replace_body`, `insert_member`) changed the value of a multi-line string literal: re-indentation added the target indentation to every continuation line of a single-quoted, double-quoted or backtick string (`'a\nb'` became `'a\n    b'`) and the common-indent removal stripped characters from them, with no error. Lines that start inside such a string are now left verbatim and do not count towards the common indentation; heredoc and nowdoc bodies still move together with their closing marker, which PHP removes again. Found by applying a `source_edit_plan` to a real test file.

### Changed

- `apply --diff` and `edit --diff` without `--dry-run` are a `usage` error instead of being silently ignored. A real apply never printed a diff, so a caller that passed `--diff` believed it had one; the message says to add `--dry-run`.

## 0.5.0 - 2026-10-22

### Added

- `agent-edit instructions` emits coding-agent workflow guidance and capabilities from the executable registry, with an optional `--with-map` intersection so hosts can expose only plans supported by the installed `agent-map` version.
- `method_copy_plan@1.0` and `method_scaffold_plan@1.0` are executable through the existing transaction and exact-candidate verifier. Both require safe PHPStan-backed Map evidence; copies with owner dependencies still refuse before mutation.
- `source_edit_plan@1.0`, a caller-authored direct-edit capability that needs no `agent-map` index: exact `find`/`replace` anchors (unique, `occurrence` or `all`), optional per-edit file digests and `creates` for new files. Refusals are actionable (match line numbers, whitespace/line-ending differences, closest line). Rewritten and created PHP is syntax-checked and published through the new generic `FileTransaction` (stage, lint, back up, publish, roll back). A token-level `SignatureGuard` refuses removing, renaming or changing the signature of a class, function or non-private method unless `allow_signature_change` is set, and records the acknowledgement.
- `agent-edit edit FILE --find ... --replace ...` (one edit; `--find-file`/`--replace-file` for multi-line text), `agent-edit apply -` (plan on stdin), `agent-edit undo --bundle=PATH` and `agent-edit schema`. Direct-edit receipts bind per-file digests and pre-images; `undo` restores them only while every touched file still has the published digest. `verify` of a direct edit needs no Map.
- `agent-edit capabilities` reports `requires_map`; `--with-map` lists Map-free capabilities under `direct` instead of `executable_not_planned`.
- Symbol-addressed edits in `source_edit_plan`: `"op": "replace_symbol" | "replace_body" | "insert_member" | "remove_symbol" | "add_use"` name a declaration (`Class::method()`, `Class::$property`, `Class::CONSTANT`, `Enum::CASE`, `Class`, `function()`) instead of copying its text. Code is re-indented to its target and keeps the file's line ending; `replace_symbol` keeps an omitted doc comment or attribute list; `insert_member` places members at the start, end, `after` or `before` a member; `remove_symbol` leaves no doubled or dangling blank line; `add_use` inserts in sorted position and is a no-op for an existing import. Unknown or ambiguous symbols are refused with the declarations the file really has. Each symbol edit resolves against the file as read and is reduced to its smallest changed byte range, so the overlap check, API guard, syntax check, transaction, receipt and `undo` apply unchanged. The JSON schema lists every edit form as a `oneOf` variant.
- `EditEngine::undo()`, `EditEngine::preflight()`/`apply()` accept a `null` Map for Map-free plans, `ApplyRequest::$mapIndexPath` may be `null` for them, and `EditResult` carries applier `evidence`.

- `apply --dry-run` (and `edit --dry-run`) lists every file it would create, modify, move or delete with added/removed line counts; `--diff` prints the unified diff. The preview is derived only from the applier's own preflight result, and a test proves the previewed diff equals the diff of the real apply. `EditReceipt` gains `plannedChanges`, `diff` and `changedFiles`; a dry-run `execution.json` records `planned_changes` (never the diff).
- `--format=json` (or `--format json`) for `apply`, `edit` and `verify`: exactly one JSON document on stdout, also for a failure, with the same exit codes as text mode. Success carries `status`, `plan`, `bundle`, `changed_files` or `planned_changes` and a `next_action` (`verify` for a Map-free edit, `refresh_map_then_verify` for a Map plan); a failure carries `error.code`, `error.next_action`, `error.hint` and `error.details`. An `incomplete` verification names its `reason` (`residue_open`, `import_residue_open` or `scope_unproven`).
- Stable failure codes (`FailureCode`): `usage`, `input_not_found`, `plan_malformed`, `plan_unsupported`, `plan_refused`, `evidence_stale`, `evidence_changed`, `map_backend_unsupported`, `anchor_missing`, `anchor_ambiguous`, `edits_overlap`, `api_change_refused`, `target_exists`, `apply_failed` and `failed`. They are carried by `EditFailure` (a `RuntimeException`) and `InvalidInputFailure` (an `InvalidArgumentException`), so existing `catch` blocks and `getMessage()` callers keep working. A refused plan's `details` list every blocker, stale-evidence and blind-spot entry (up to 20). Failure sites that are not migrated stay unclassified (`failed`); nothing is guessed from message text.

- `method_copy_plan` / `method_scaffold_plan` hardening (tests in `MethodInsertionHardeningTest`, 40 cases, mutation-checked): a copy that needs a `use` import for a class the destination lacks is now executable (the planner publishes that import as a zero-length insertion, which the decoder used to reject, so such copies never ran); the declaration insertion must replace exactly the destination class's closing brace and, per the PHP tokenizer, declare exactly the named method and no other class member; every edit must target one file; the destination class, its closing line and the absence of a same-named method are re-proven against the Map; `verify` requires the rebuilt Map to declare the method on the destination class; owner dependencies are a classified `plan_refused` with the dependencies in `details`. A copy whose body uses a file-level `use X as Y` alias is refused, because agent-map imports the aliased class under its real name (voku/agent-map#158). The insertion text is parsed (nikic/php-parser) as the tail of the destination class: it must close exactly that class and add nothing after it. The declaration must replace the destination class's own closing-brace byte, not any `}` on the planned line, and a `use` import must sit exactly at the file's import slot (after the last import, else after the `namespace` line), as agent-map's planner places it.
- The shared edit applier and verifier understand a zero-length insertion (`expected` empty, start equal to end, text inserted before the byte at that offset). Only the insertion decoder accepts one; every other plan family still rejects an empty `expected`. An insertion at the very end of a file is legal, one past it is refused, and two insertions at one offset are refused as ambiguous.

- `class_scaffold_plan@1.0` is executable (decoder `ClassScaffoldPlanDocument`, applier on the shared `FileTransaction`, verifier `ClassScaffoldVerifier`): one new file whose content is proven, by parsing it (nikic/php-parser), to declare exactly the named type in the named namespace and nothing executable at the top level (a `declare` may not govern a statement or block); provenance, a Map that does not already declare the class and a free destination are re-proven; `verify` requires exactly the planned bytes at exactly that path. The Git-free scope observation now also records the absence of a path a plan creates.

### Changed

- An unsupported plan names the type and contract version it saw; an unparsable plan names the file and the parser message instead of a bare `Syntax error`.

### Fixed

- A `source_edit_plan` whose result is not valid PHP is refused while the plan is prepared, with the parser message and the offending line, so `--dry-run` no longer reports `prepared` for it (previously only the publishing `php -l` caught it).
- Rename and move verification matched a rewritten token as a substring: renaming `greet` to `name` was "verified" by the `name` inside `$name` after the call site was reverted, although the code then called an undefined method. Identifier-shaped replacements now only count as whole identifiers, and a bare name never as part of a `$variable`.
- Re-running `apply` into a bundle that records an applied plan overwrote its receipt with `runner_failed` while the old `passed` verification result stayed. Such a bundle is now never overwritten (also not by a dry run); any other bundle drops a stale verification result before a new receipt is written. `undo` likewise removes the verification result of the state it restored.
- Verifying an edit of a file Git ignores no longer fails with "changed_files do not match plan scope": ignored plan files are recorded at apply time, proven by digest and reported in `git_ignored_files_proven_by_digest_only`.
- `apply --map-root` outside the project root was accepted, producing a bundle `verify` can never check; it is now refused before any write. An absolute `--output-dir` named through a symlinked directory (a shell's `$PWD`, macOS `/tmp`) is resolved before the inside-root check instead of being refused.
- A missing plan file reported `Refactor refactor plan not found`; it now reads `Plan file not found` (and `Map index not found`, `Map root not found`).
- The top-level help listed `verify` without `--accept-residue`, and `verify --help` omitted class removal plans and the `use` import residue check.
- Residue verification trusted the scanner's capped reference list: with more than 200 historical mentions (a long `CHANGELOG.md` sorts first) it reported `open: 0` yet kept an otherwise passed result `incomplete`, and real mentions later in path order were never counted. The scan is now uncapped, `open`/`historical` are exact, and only the listed open references are capped at 50 (`truncated` says so).
- `FileTransaction` published a rewritten file by moving the original to its backup and then moving the staged file in, so for a moment the file did not exist. A parallel `agent-edit edit` whose unlocked preflight read it then failed with "does not exist as a regular file" (seen in CI). The original is now kept in place (hard-link backup, copy where links are unsupported) and replaced by one atomic rename; rollback renames the backup back over a rewritten or deleted path. Staged files and copied backups are created with their final mode before any byte is written, so a `0600` file's content is never briefly readable under the umask default, and a backup is registered before it is created so rollback removes a partial one.

## 0.4.4 - 2026-10-09

### Added

- `verify` reports `use` imports orphaned by a `method_removal_plan`, `method_move_plan`, `property_removal_plan` or `class_constant_removal_plan` deletion: an import whose alias occurred in the deleted text and no longer occurs in the edited file is listed in an `import_residue` block (`clear`, `open` or `accepted`). While open, an otherwise `passed` result is `incomplete` (CLI exit code 3); `--accept-residue=REASON` records a disposition. `apply` still performs only the plan's exact edits.

## 0.4.3 - 2026-10-09

### Changed

- The `verify` residue scan now also covers `class_removal_plan`: Markdown and template mentions of the removed class keep an otherwise `passed` verification `incomplete` until fixed or accepted with `--accept-residue`.

## 0.4.2 - 2026-10-08

### Changed

- Accept `voku/agent-map` `^0.20.0 || ^0.21.0 || ^0.22.0`, so installs can resolve agent-map 0.22.0 (adds `MapPreparationService::rebuild()` and output materialization; no plan contract changes). The suite and PHPStan pass against agent-map 0.22.0.

## 0.4.1 - 2026-10-08

### Changed

- Accept `voku/agent-map` `^0.20.0 || ^0.21.0`, so installs can resolve agent-map 0.21.0 (adds `agent-map watch`; no plan contract changes). The suite and PHPStan pass against agent-map 0.21.0.

## 0.4.0 - 2026-10-06

### Added

- `class_removal_plan@1.0` is executable: `apply` deletes the single file owned by the target class after re-proving plan provenance, the file hash, sole declaration and absence of incoming Map evidence (restored on failure), and `verify` requires the file and class to be absent from a rebuilt Map with `changed_files` exactly the deleted path. `review_required`, `blocked`, stale, edit-carrying, move-carrying or multi-deletion plans are refused before any change.

## 0.3.0 - 2026-10-06

### Added

- `verify` re-scans Markdown and Twig / Smarty / Blade files for the old symbol after a `method_rename_plan`, `class_rename_plan` or `method_removal_plan` and records a `residue` block (`clear`, `open` or `accepted`, with counts and the first non-historical references) in `verification-result.json`. While non-historical mentions remain, an otherwise `passed` result is `incomplete` (CLI exit code 3); changelog-style files count as historical and never block. `--accept-residue=REASON` (`verify(..., acceptResidue:)`) records a disposition and keeps the verdict `passed`. Requires `voku/agent-map` `^0.20.0`.

### Fixed

- Git command path output now accepts CRLF line endings without treating the carriage return as part of the repository or project path.

## 0.2.2 - 2026-10-05

### Changed

- A refused plan now explains itself with the owner's own evidence: the refusal sentence is followed by the plan status and the first Map blockers, stale-evidence entries or blind spots (bounded, with a `(+N more)` count), for all six plan families. A removal blocked by a live call site now names that call site instead of only saying "not safe".
- `agent-edit apply` reports an unknown option together with the accepted ones (`--task`, `--map-index`, `--map-root`, `--output-dir`, `--dry-run`) and no longer calls its own options "refactor" options.

### Fixed

- Git-free file-move receipts now observe both the removed source and the new destination by recording validated destination absence before publication.
- A failed verification attempt now removes the previous verification result so consumers cannot read an outdated `passed` verdict.
- Git working-tree observations preserve whitespace in repository and project directory names, including changes to already dirty files.
- A refusal that produces no receipt (dry-run preflight refusal of a `blocked` plan, unsupported plan type/contract version, host authorization refusal) no longer leaves an empty receipt bundle directory behind. Found by replaying a real fail-closed `method-removal-plan` against `agent-recall-compiler`: hosts that count every bundle directory of a task would read the empty one as a missing verification result.

## 0.2.1 - 2026-10-04

### Fixed

- A receipt write failure after a successful publication now raises `ReceiptNotPersistedException` (naming the changed files and stating that no receipt exists) instead of an anonymous "Unable to publish refactor evidence" error, and a failure-receipt write error no longer masks the original apply failure. Receipt writes no longer emit PHP warnings on `rename`/`file_put_contents` failure.

### Added

- A real two-process `MutationLock` proof covers serialization for the same project (including symlinked root spellings), independence across different projects, and lock recovery after a killed holder process.

## 0.2.0 - 2026-10-04

### Added

- Git-free verification evidence: when Git is unavailable, `applyWithReceipt` stores a bound `map-scope-before.json` (Map-indexed path → sha256) and records `changed_files_source: map_manifest_diff`; `verify` diffs it and returns `status: incomplete` / `scope.status: scope_unproven` (CLI exit 3), never `passed`. Receipts and results produced with Git are unchanged. New public contract: `changed_files_source: map_manifest_diff`, `scope_evidence`, `status: incomplete`, `scope.status: scope_unproven` and CLI exit code `3`.

### Changed

- The snapshotter observation contracts (subdirectory Git root, no repository, untracked/deleted files, linked worktree) are now covered by agent-edit's own test suite.

## 0.1.1 - 2026-10-04

### Fixed

- A failed authorized mutation attempt now persists a `runner_failed` receipt after the rollback has been observed (while the mutation lock is still held), then rethrows the original exception. Authorization refusals and failures before the pre-mutation snapshot remain receipt-free.

### Added

- Standalone real-filesystem publication-rollback dogfood in CI, and README documentation of the failed-mutation receipt contract.

## 0.1.0 - 2026-10-04

### Added

- Initial extraction of the deterministic refactor mutation subsystem from `agent-loop` (`src/Edit/Refactor`): plan documents and provenance evidence, the shared transactional applier, the six rename contracts plus class/method move and method/property/class-constant removal, and their verifiers.
- `EditEngine` package API (`preflight`, `apply`, `applyWithReceipt`, `verify`), `agent-edit apply|verify|capabilities` CLI and the executable capability registry (`capabilities --with-map` intersects with `agent-map plan-capabilities`).
- Removed loop coupling: no `ProjectLayout`, no `ExecutionContractStore`; host authorization is an injected `authorizeMutation` hook. The CLI is a thin adapter over `EditEngine`.
