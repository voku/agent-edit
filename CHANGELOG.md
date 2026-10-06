# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

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
