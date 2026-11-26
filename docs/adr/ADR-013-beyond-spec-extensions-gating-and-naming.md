# ADR-013: Gating Beyond-Spec Extensions via ValidatorPolicy & Dual x-* Naming

## Context

php-arazzo introduced proprietary extensions that are carried as first-class enum values in `packages/contracts`:
- the `invoke` action kind (`ActionKind::Invoke`), enabling steps and workflows to invoke child workflows directly from `onSuccess` / `onFailure`;
- parameter locations `body`, `querystring`, `metadata`, and `variable` in `ParameterIn` (the official Arazzo spec enum only permits `path|query|header|cookie`).

Because these extensions were neither `x-`-prefixed nor gated behind a conformance mode, conformance validation runs could not distinguish official Arazzo documents from proprietary workflows.

## Decision

1. **Dual Extension Syntax in Permissive Mode**:
   Documents may write either canonical `x-*` prefixes (`type: x-invoke`, `in: x-body`, `in: x-querystring`, `in: x-metadata`, `in: x-variable`) or legacy bare names (`invoke`, `body`, ...). Both are accepted in permissive mode.
2. **DTO Representation**:
   `Parameter` and `Action` DTOs retain canonical enum cases (`ParameterIn::Body`, `ActionKind::Invoke`) while capturing the raw textual spelling (`rawIn`, `rawType`) so runtime and validation layers can inspect extension status without breaking internal downstream matches.
3. **Strict Conformance Gating**:
   `ValidatorPolicy` (introduced in ADR-012) is extended with `conformance: false` (default permissive). When `conformance: true` (activated via `Document::withValidatorPolicy()` or `arazzo validate --conformance`), `StepParameterInValidRule` and `ActionTypeValidRule` reject all beyond-spec extensions (both bare and `x-`-prefixed enum variants) because the official Arazzo spec defines a closed enum for parameter locations and action types.
4. **Documentation**:
   All supported extensions are cataloged in `docs/EXTENSIONS.md`.

## Considered Options

- **Parse-Time Rejection**: Throwing `ParserException` in strict mode. Rejected because structural validation in `packages/document` belongs in `Validator` rules to report precise JSON Pointer errors and allow linting tools to report multiple issues.
- **Requiring `x-*` Exclusively Across All Modes**: Breaking backwards compatibility for existing workflows. Rejected in favor of permissive defaults with dual syntax support.
- **Permitting `x-*` Enums in Conformance Mode**: Allowing `in: x-body` in strict conformance mode. Rejected because the Arazzo specification defines `in` as a closed enum (`path|query|header|cookie`) and only allows `x-` extensions on objects (property keys).

## Consequences

- Conformance test runners (`arazzo validate --conformance`) reliably reject any workflow relying on proprietary extensions.
- Existing workflows using bare names (`invoke`, `in: body`) continue to parse and execute unchanged in default (permissive) mode.
- Users can write forward-compatible documents using canonical `x-*` naming.
