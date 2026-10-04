# AGENTS.md

## Repository role

`voku/agent-edit` owns deterministic, evidence-backed source mutation: validating versioned `agent-map` plans, applying exact edits and file moves transactionally, publishing receipts and verifying the applied result.

## Dependency direction

`agent-map` is below `agent-edit`; `agent-edit` is below `agent-loop`. Never depend on `agent-loop`, `agent-recall-compiler` or any LLM client. Host authorization is injected (`ApplyCommand::$beforeMutation`), never imported.

## Invariants

- A plan is evidence, never authority. Revalidate provenance, source hashes, byte ranges, blockers, review-required state and staleness immediately before writing, and again under the mutation lock.
- Fail closed. `review_required`, blocked, stale or conflicting plans never write.
- Publication is all-or-nothing: stage, syntax-check, back up, publish, roll back every source on failure.
- Do not invent edits, destinations or refactoring candidates. Unsupported plan types stay explicit rejections.
- `CapabilityRegistry` is the single list of executable contracts; `EditEngine`, the CLI and docs must not diverge from it.
- No git commit/push, no network, no model calls.

## Validation

Run `composer ci`. Tests that need a `+phpstan` map require `phpstan/phpstan` to be installed.
