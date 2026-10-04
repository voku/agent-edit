# Changelog

## Unreleased

- Initial extraction of the deterministic refactor mutation subsystem from `agent-loop` (`src/Edit/Refactor`): plan documents and provenance evidence, the shared transactional applier, the six rename contracts plus class/method move and method/property/class-constant removal, and their verifiers.
- `EditEngine` package API (`preflight`, `apply`, `applyWithReceipt`, `verify`), `agent-edit apply|verify|capabilities` CLI and the executable capability registry (`capabilities --with-map` intersects with `agent-map plan-capabilities`).
- Removed loop coupling: no `ProjectLayout`, no `ExecutionContractStore`; host authorization is an injected `authorizeMutation` hook. The CLI is a thin adapter over `EditEngine`.
