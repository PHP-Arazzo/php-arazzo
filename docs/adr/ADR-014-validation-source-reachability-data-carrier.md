# ADR-014: Validation source reachability data carrier and dedicated integrity rules

## Status

Accepted

## Context

Issue #331 introduces `--resolve-sources` to `arazzo validate` to check that all source descriptions are readable, parseable, and conform to their declared types.

Per ADR-005, the `document` validator must never import `sources`, keeping validation pure model logic with no direct I/O, network, or Guzzle dependencies. Furthermore, base validation without `--resolve-sources` must stay offline.

## Decision

1. **Contracts Data Carrier**: `packages/contracts` defines `SourceResolution`, `SourceResolutionStatus`, `SourceResolutionCollection`, and `SourceInspectionResult`. `SymbolTable` carries `SourceResolutionCollection`.
2. **Sources Inspection Seam**: `packages/sources` (`SourcesInterface`) exposes `inspectSources(ArazzoDocument $document, string $basePath = ''): SourceInspectionResult`. It fetches and parses sources once, returning both the integrity statuses and the `KnownOperations` derived from valid sources (satisfying R1 single-fetch invariant).
3. **Four Dedicated Validation Rules**: `packages/document` implements four distinct rules auto-discovered via attributes:
   - `SourceUnreadableRule` (`source.unreadable` at `/sourceDescriptions/{i}/url`)
   - `SourceUnparseableRule` (`source.unparseable` at `/sourceDescriptions/{i}/url`)
   - `SourceTypeMatchesRule` (`source.type_matches` at `/sourceDescriptions/{i}/type`)
   - `SourceTypeInferenceFailedRule` (`source.type_inference_failed` at `/sourceDescriptions/{i}/url`)
   When `--resolve-sources` is not supplied, these rules no-op.
4. **Offline Base Validation**: Without `--resolve-sources`, `ValidateCommand` only resolves local `file://` sources offline for `knownOperations`, making zero outbound network requests.

## Consequences

- The `document` package remains pure and decoupled from I/O while still evaluating source integrity diagnostics uniformly via `RuleSet` and `ValidatorPolicy`.
- Users can selectively override severity or disable individual source diagnostics via standard CLI flags (`--severity source.unreadable:warn`, `--disable-rule source.type_matches`).
- Source documents are fetched and parsed at most once per validation invocation.
