# php-arazzo — Domain Context & Glossary

This file is the project's shared domain model (CONTEXT.md). It defines the
ubiquitous language used across issues, design reviews, ADRs, and code. Keep it
in sync with the code: when a term's meaning changes, update this file in the
same pull request.

## 1. Project

| Term | Meaning |
|------|---------|
| **php-arazzo** | PHP implementation of the [Arazzo 1.0.1 / 1.1.0](https://www.asyncapi.com/specs/arazzo) spec for describing workflows of API calls. |
| **Monorepo** | Single git repository containing multiple Composer packages under `packages/`, plus `scripts/` and tooling at the root. |
| **`arazzo run`** | CLI command that resolves and executes an Arazzo workflow document: sends HTTP requests, extracts step outputs, evaluates criteria, applies retry/goto/failure semantics, and reports a true result. |
| **`arazzo validate`** | CLI command that lint-checks an Arazzo document: structure, references, expression grammar, parameter/action rules, dependsOn validity, source qualification. |

## 2. Conformance

| Term | Meaning |
|------|---------|
| **arazzo-conformance** | The [`api-evangelist/arazzo-conformance`](https://github.com/api-evangelist/arazzo-conformance) scored test suite (SHA `6e93591` is the conformance oracle). php-arazzo targets 100% of scored validation cases (127) and 100% of scored execution cases (14). |
| **Conformance capability manifest** | Machine-readable JSON at `docs/conformance/manifest.json` recording, per capability, whether php-arazzo supports it (`supported` / `partial` / `unsupported` / `not-applicable`), with `evidence` references. Emitted by `scripts/generate-conformance-matrix.php` alongside `docs/CONFORMANCE.md`. |
| **Drift guard** | `scripts/check-conformance-manifest.php`: re-runs the corpus, rebuilds the manifest model, compares to the committed manifest, exits non-zero on mismatch. Runs in CI on every PR. |
| **OAI corpus** | Vendored official OAI Arazzo examples under `packages/core/tests/Conformance/corpus/oai/`. Source of truth for what the manifest claims. |

## 3. Execution

| Term | Meaning |
|------|---------|
| **Workflow** | A unit of execution in an Arazzo document, identified by `workflowId`, containing steps. |
| **Step** | An action within a workflow: an `operationId` call, a `workflowId` sub-workflow invocation, or a `successAction`/`failureAction`. |
| **dependsOn** | Declares that a step or workflow must wait for another to complete. Workflow-level `dependsOn` (issue #341) runs dependency workflows before the target. Cross-workflow step `dependsOn` (`$workflows.<id>.steps.<stepId>`, issue #388) references steps in sibling workflows. |
| **Retry** | `onFailure.retry.retryLimit: N` means N+1 total requests (issue #382 R2). |
| **Goto** | Step-level (`onSuccess`/`onFailure`) or workflow-level (`successActions`/`failureActions`) action transferring execution to a target step (`stepId` within current workflow) or workflow (`workflowId` within document), skipping intermediates (issue #382 R3/R4, #343). |
| **Success criteria** | `successCriteria` evaluated via `EvaluationEngine`: regex match, body comparison, simple comparison (issue #382 R5). |
| **Sub-workflow (workflowId step)** | A step that references another workflow by `workflowId`; the child runs and its outputs are exposed as `$steps.<childStepId>.outputs` (issue #382 R6). |
| **ExecutionState** | Mutable in-flight working state of an active run (`packages/contracts/src/State/ExecutionState.php` — inputs, step results, outputs, attempts, budget, call stack). Stored in hot state between queued jobs; evictable, not the source of truth for audit or replay. |
| **ExecutionHistory** | Append-only, monotonically sequenced log of execution facts — the canonical source of truth for audit trails, deterministic replay, and lease fencing (`persist-68`). _Avoid_: `EventLedger`. |
| **ExecutionSnapshot** | Immutable, content-hashed (`sha256`), sequence-pinned durable freeze of `ExecutionState` written after terminal steps or joins so workers resume crashed or long-parked runs in O(1) without full-history replay (`persist-70`, `persist-120`). _Avoid_: `SuperstepCheckpoint`. |
| **Runner** | Synchronous, protocol-agnostic, in-process workflow runner facade (`Alama\Arazzo\Runner\Runner`) that executes steps exclusively via `OperationExecutorPluginInterface` plugins. _Avoid_: `RunnerFacade`. |
| **AsyncRunner** | Durable, queue-driven workflow runner facade (`Alama\Arazzo\Runner\AsyncRunner`) for hosts executing persisted steps and resuming suspended correlations. _Avoid_: `RunnerGraphBuilder`, `AsyncGraphSeams`. |
| **ExecutionResult** | Immutable typed outcome returned by `Runner::run()`, carrying workflow status (`ExecutionStatus`), outputs, per-step `StepResult` verdicts, steps spent, and call stack. |
| **Preflight** | Execution-readiness gate run before the first step side effect: validates workflow `inputs` against JSON Schema (always when enabled) and step operation resolution when `OperationResolverInterface`s are registered. |
| **OperationExecutorRegistry** | Concrete singleton registry (`Alama\Arazzo\Runner\Registries\OperationExecutorRegistry`) holding globally registered `OperationExecutorPluginInterface` plugins ordered by `OperationProtocol`, overridden per-instance via `withExecutor()`. |
| **ExecutionStore** | Single coarse persistence port (`Alama\Arazzo\Runner\ExecutionStoreInterface`) injected into `AsyncRunner` via `withStore()`, owning in-flight execution state, lifecycle status, and pending correlations for OSS durable runs. |
| **Policy (`*Policy`)** | Monorepo-wide convention for immutable rule/limit value objects (`final readonly`, `DEFAULT_*` constants, `::default()`, immutable `with*()` methods — e.g. `DecodePolicy`, `YamlPolicy`, `RetryPolicy`, `ExecutionPolicy`). _Avoid_: `*Options`, `*Settings`, `*Config` on public APIs. |
| **ExecutionPolicy** | Immutable execution rules/limits DTO (`Alama\Arazzo\Runner\Policy\ExecutionPolicy`) configuring budgets (`maxSteps`, `maxWorkflowDepth`), retry, preflight, state TTL, and source-description-scoped policies. |
| **SourcePolicy** | Strict, immutable per-source policy DTO (`Alama\Arazzo\Contracts\Policy\SourcePolicy`) addressed by `sourceDescriptions[*].name`, carrying server overrides, redacted `Credential`s, headers, transport settings, and protocol extensions so API settings never leak across sources. |
| **Tracer** | Vendor-agnostic distributed tracing seam (`Alama\Arazzo\Contracts\Observability\TracerInterface`) shared across I/O and orchestration packages (defaulting to `NullTracer`), bridged to OpenTelemetry via an external adapter (`OtelTracer`). |
| **Clock** | PSR-20 `ClockInterface` time provider injected via `withClock()` (defaulting to `SystemClock`) for deterministic timestamps, retry timing, and replayable execution. |

## 4. Validation

| Term | Meaning |
|------|---------|
| **RuleSet** | Collection of validator rules in `packages/document/src/Validator/RuleSet.php`. |
| **Source qualification** | Requiring `operationId` to be source-qualified (e.g. `sourceName.operationId`) only when multiple non-arazo sources exist; single source allows unqualified (issue #388 R2/C1). |
| **Cross-workflow dependsOn** | Validator accepts `$workflows.<id>.steps.<stepId>` in `dependsOn` (issue #388 R1). |
| **Strict validation** | Default `true`: a response violating its OpenAPI response schema fails the step (issue #334). Opt-out per step via `x-strict-validation: false`. |
| **Reference resolution (validate)** | `operationId` is resolved against locally-loaded source operations; `$sourceDescriptions.<name>` and `$components.<type>.<name>` are checked against the symbol table. Local sources resolve offline; unreachable remote sources are skipped, never fail validation (issue #384). |
| **Source resolution seam** | Performed in `cli` (`ValidateCommand` → `SourceLoader`); the `document` validator never imports `sources` (issue #384). |
| **Replacement target uniqueness** | Within a step's `requestBody.replacements`, each `target` must be distinct. Duplicate targets are rejected with error code `step.request_body_replacements_duplicate_target` pointing to the duplicate entry. |
| **ExpressionSite** | Value object carrying JSON Pointer, `Expression`, symbol table reference, `currentStepId`, and traversal `context` identifying an expression site in an Arazzo document for static validation rules. |
| **ExpressionWalker** | Internal traversal engine in `packages/expression` that yields `ExpressionSite`s across workflows, steps, and action flows (criteria and parameters) for static analysis. |

## 5. Sources & Identification

| Term | Meaning |
|------|---------|
| **Retrieval URI** | The physical transport address or filesystem path from which a document was retrieved (e.g., `file:///specs/doc.arazzo.yaml`, `https://api.example.com/doc.arazzo.yaml`). Stored on `SourceDocument::$canonicalUri` for backward compatibility, and on `SourceIdentity::$retrievalUri`. _Avoid_: `PhysicalUri`, `FetchAddress`, `FetchPath`. |
| **Identity URI (`$self`)** | The author-declared, permanent canonical identifier of the document declared in the root `$self` property (Arazzo 1.1.0 §5.2). MUST NOT contain a fragment. When relative, it is resolved against the Retrieval URI. Stored on `SourceDocument::$selfUri` and `SourceIdentity::$selfUri`. _Avoid_: `DocId`, `DocumentUri`. |
| **Base URI** | The URI against which relative references (e.g., `sourceDescriptions[*].url`) within the document are resolved. RFC 3986 §5.1 hierarchy: equals **Identity URI** if present; falls back to **Retrieval URI** when absent. Exposed via `$sourceDoc->baseUri()`. |
| **Canonical URI** | Historically in `php-arazzo`, `$canonicalUri` held the normalized Retrieval URI. Under Arazzo 1.1.0, the true canonical URI is the Identity URI (`$self`). The property `SourceDocument::$canonicalUri` is retained for backward compatibility and is equivalent to the Retrieval URI. |
| **SourceIdentity** | Immutable value object (`Alama\Arazzo\Contracts\Spec\SourceIdentity`) binding a document's `retrievalUri`, optional `selfUri`, and resolved `SourceDocument`. |
| **SourceIdentityCollection** | Immutable collection of `SourceIdentity` entries providing O(1) identity-first then retrieval-location-second lookup, enforcing idempotency and rejecting duplicate `$self` claims from different retrieval locations. |

## 6. Packages

| Package | Role |
|---------|------|
| `packages/contracts` | State model: `ExecutionState`, `WorkflowContext`, `WorkflowDataMap` (workflow-output map owner — issue #341). |
| `packages/document` | Document parsing & validation rules (issues #388, document-level bugfixes). |
| `packages/evaluation` | `EvaluationEngine` — success criteria evaluation (issue #382 R5). |
| `packages/expression` | Runtime expression parsing & JSONPath (ABNF strictness, issue #386). |
| `packages/engine` | Transport-agnostic request pipeline helpers (`RequestCompiler`, `StepParameterMerger`, `IdempotencyKeyInjector`) and runtime state/policy/telemetry utilities. |
| `packages/runner` | Protocol-agnostic execution engine: `Runner` (sync), `AsyncRunner` (durable/queue), `ExecutionResult`, `Preflight`, and workflow lifecycle events. |
| `packages/protocol-http` | PSR-18 HTTP/OpenAPI + AsyncAPI protocol slice (`HttpProtocol`, `HttpStepExecutor`, response validation). |
| `packages/sources` | Source resolution & reachability (`Sources`, `SourceNormalizerRegistry`). |
| `packages/cli` | Console terminal edge: ships the `arazzo` executable binary (`bin/arazzo`), providing `run`, `validate`, `list-workflows`, `explain`, and `render` commands. |

## 7. Architecture decisions (ADRs)

| ADR | Decision | Issue |
|-----|----------|-------|
| ADR-001 | Strict response validation default (`true`) — semantic BC break | #334 |
| ADR-002 | Conformance capability manifest + drift guard | #340 |
| ADR-003 | Runner semantics: retry/goto/criteria/workflowId via shared seam | #382 |
| ADR-004 | Cross-workflow dependsOn + source qualification in RuleSet | #388 |
| ADR-005 | Validation degrades gracefully on unreachable remote sources | #384 |
| ADR-006 | Document package public interface & preflight boundary | #447 |
| ADR-007 | Expression package public interface & unified plugin registries | #457 |
| ADR-008 | `As<Name>Plugin` attributes & `src/Plugins/<Name>` directory convention | #457 |
| ADR-009 | Runner package public interface (`Runner` / `AsyncRunner`), `ExecutionStore`, `ExecutionPolicy` & protocol-agnostic execution | — |
| ADR-010 | Monorepo observability tiers, vendor-agnostic `TracerInterface` & `*Policy` value-object standard | — |
| ADR-011 | Static expression walking for action criteria & parameters with step/workflow context isolation | #344 |

## 8. How to update this file

When a design decision changes a term above, update CONTEXT.md in the same pull request as the implementation. Do not let the glossary drift from the code.
