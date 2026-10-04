# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

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
