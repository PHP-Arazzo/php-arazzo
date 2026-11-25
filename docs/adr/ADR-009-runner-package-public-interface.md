# ADR-009: Runner Package Public Interface and Protocol-Agnostic Execution

## Status

Accepted

## Context

The `php-arazzo/arazzo-runner` package (`packages/runner`) is the Arazzo workflow execution engine serving both synchronous in-process execution and durable queue-driven execution. Following the public interface redesigns of `document` (ADR-006), `sources`, and `expression` (ADR-007/ADR-008), an audit of `packages/runner` revealed seven structural problems:

1. **Undeclared Protocol Coupling (DIP Violation)**: Although `packages/runner/composer.json` does not require `protocol-http`, six source files in `packages/runner/src/` directly imported `Alama\Arazzo\Protocol\Http\*`. `StepExecutor` maintained a hardcoded `executeHttp()` branch alongside `OperationExecutorPluginInterface`.
2. **High-Friction Facade & Untyped Outputs**: `RunnerFacade` required a 7-parameter constructor (including a dead `$inspector` argument), offered no `::create()` factory or immutable `with*()` builders, and flattened `ExecutionResult` into untyped associative arrays across two redundant methods (`run()` and `execute()`).
3. **Fragmented Persistence Ports & `@internal` Leakage on the Async Host Seam**: `RunnerGraphBuilder`, `AsyncGraphSeams` (20 constructor parameters), `AsyncExecutionGraph`, `ExecuteStepJob`, `SyncQueueDriver`, and `OperationExecutorRegistry` were marked `@internal`, and hosts had to override five separate fine-grained query/write persistence interfaces (`StateStore`, `EventLedger`, `ExecutionRegistry`, `PendingCorrelationRegistry`, `DefinitionRegistry`). Moreover, `EventLedgerInterface` was write-only, duplicated PSR-14 lifecycle events, and pushed Platform/PaaS audit concerns into OSS execution.
4. **Hardcoded OpenTelemetry & Missing Sync Observability**: `StepExecutionWorker` called static `OtelSetup::getTracer()` directly (coupling `runner` to `open-telemetry/api`), sync `Runner` had zero tracing, `psr/log` was declared but unused, and wall-clock `new DateTimeImmutable()` calls prevented deterministic replay/testing.
5. **Dual PSR-4 Autoload Root & Internal Events/Exceptions**: `packages/runner` declared a legacy second PSR-4 root (`Alama\Arazzo\Events\`). Both the 9 lifecycle event DTOs and all execution exceptions (`Execution\Exceptions\*`) were marked `@internal`.
6. **Bloated Vendor Dependencies**: `packages/runner/composer.json` declared nine unused or vendor-coupled dependencies (`arazzo-sources`, `guzzlehttp/guzzle`, `psr/http-client`, `psr/http-factory`, `psr/http-message`, `psr/simple-cache`, `open-telemetry/api`, `open-telemetry/sdk`, `open-telemetry/exporter-otlp`).
7. **Dead / Superseded Classes**: `ProtocolExecutorRegistryInterface`, `Execution\Interfaces\OpenApiExecutorInterface`, `UnifiedStepCarrier`, `ResponseValidatorDispatcher`, and `ResumeCorrelationJob` had zero production callers.

## Decisions

### 1. Dual Public Facades: `Runner` (Sync) and `AsyncRunner` (Durable/Queue)
Replace `RunnerFacade` / `RunnerFacadeInterface`, `RunnerGraphBuilder` / `RunnerGraphBuilderInterface`, `AsyncGraphSeams`, and `AsyncExecutionGraph` with two `final readonly` facades following the `Document` / `Sources` / `ExpressionEngine` pattern (`::create()`, public autowirable constructor, immutable `with*()` builders) sharing identical policy, executor, resolver, and observability seams:

- **`Alama\Arazzo\Runner\Runner` implements `RunnerInterface`** (synchronous in-process execution):
  - `run(ArazzoDocument $document, string $workflowId, array $inputs = [], ?ExecutionPolicy $policy = null): ExecutionResult`
  - `preflight(ArazzoDocument $document, ?string $workflowId = null, array $inputs = []): ValidationResult`
  - Shared immutable builders: `withPolicy()`, `withExecutor()`, `withOperationResolver()`, `withEventDispatcher()`, `withLogger()`, `withTracer()`, `withClock()`.
  - When preflight is enabled, workflow `inputs` JSON Schema validation always runs; step operation-resolution preflight runs whenever at least one `OperationResolverInterface` is registered (skipping silently when none are registered).
- **`Alama\Arazzo\Runner\AsyncRunner` implements `AsyncRunnerInterface`** (durable/queue-driven execution):
  - Ships public in-memory defaults (`InMemoryExecutionStore`, `InMemoryDefinitionStore`, `SyncQueueDriver`) plus internal lock/output-extractor defaults on `AsyncRunner::create()`.
  - Durable infrastructure builders: `withStore(ExecutionStoreInterface)`, `withDefinitions(DefinitionStoreInterface)`, `withQueueDriver(QueueDriverInterface)`, `withLockManager(LockManagerInterface)`, `withOutputExtractor(OutputExtractorInterface)` — plus the exact same shared policy, executor, resolver, and observability builders as `RunnerInterface`.
  - Four explicit verbs: `start()`, `resume()`, `handleStep(ExecuteStepJob $job)`, `resumeCorrelation(string $correlationId, array $response)`.

### 2. Coarse OSS Persistence Ports Owned by `packages/runner`
- Keep `packages/contracts` razor-thin (cross-package primitives only). Define runner-specific persistence ports in `packages/runner`:
  - **`Alama\Arazzo\Runner\ExecutionStoreInterface`** (default: `Store\InMemoryExecutionStore`): single coarse repository owning in-flight hot execution state (`saveState`/`loadState`/`deleteState`), run lifecycle status (`markStarted`/`markCompleted`), and pending correlations (`saveCorrelation`/`findCorrelation`/`consumeCorrelation`/`hasPendingCorrelations`).
  - **`Alama\Arazzo\Runner\DefinitionStoreInterface`** (default: `Store\InMemoryDefinitionStore`): single version-pinned `find()` / `save()` port separating immutable workflow specs from mutable hot execution state.
- **Retire `EventLedgerInterface` & `LedgerEventListener`**: Delete `EventLedgerInterface` from `contracts` and `LedgerEventListener` / `NullEventLedger` from `runner` and `cli`. OSS runners emit typed PSR-14 lifecycle events and structured PSR-3 diagnostics; future durable event sourcing (`persist-68`) and verified snapshots (`persist-70`) will be introduced cleanly under the `ExecutionHistory` and `ExecutionSnapshot` domain terms.

### 3. `ExecutionPolicy` & Source-Addressed `SourcePolicy`
- Adopt the monorepo `*Policy` value-object convention (see ADR-010) with `Alama\Arazzo\Runner\Policy\ExecutionPolicy` (`final readonly`, `ExecutionPolicy::default()`, immutable `withMaxSteps()`, `withMaxWorkflowDepth()`, `withRetryPolicy()`, `withPreflight()`, `withStateTtlSeconds()`, `withSourcePolicy()`).
- Inspired by `usearazzo/arazzo-toolkit#345`, per-API configuration (`Alama\Arazzo\Contracts\Policy\SourcePolicy` + secret-redacting `Credential`) is strictly addressed by `sourceDescriptions[*].name` — never a flat run-wide bag:
  - Fail-fast validation before step 1 throws `InvalidSourcePolicyException` for unknown keys, `type: arazzo` sources, dotted keys (reserved for nested Arazzo), or duplicate source URLs.
  - The active step's `SourcePolicy` is attached transiently to `WorkflowContext::withActiveSourcePolicy()` for that step call only and excluded from `WorkflowContext::toArray()` so credentials never leak across APIs or into persisted hot state.

### 4. 100% Protocol-Agnostic Execution & Typed DTOs
- `StepExecutor` executes all steps exclusively through `OperationExecutorPluginInterface` (per-instance executors layered over `Registries\OperationExecutorRegistry`, with `SubWorkflowStepExecutor` wired per graph).
- `packages/protocol-http` exposes `Alama\Arazzo\Protocol\Http\HttpProtocol` (`executor()`, `resolver()`, `outputExtractor()`).
- Promote `Data\{ExecutionResult, StepResult, ExecuteStepJob}` (`final readonly`), `Queue\SyncQueueDriver`, `Registries\OperationExecutorRegistry`, `Store\{InMemoryExecutionStore, InMemoryDefinitionStore}`, `Policy\{ExecutionPolicy, ExecutionBudgets}`, `Events\*` (single PSR-4 root), and `Exceptions\*` (all extending `RunnerException extends ArazzoException`) to public API.

### 5. Vendor-Agnostic Observability & Determinism (see ADR-010)
- Wire `Psr\Log\LoggerInterface` (default `NullLogger`), `Alama\Arazzo\Contracts\Observability\TracerInterface` (default `NullTracer`), `Psr\EventDispatcher\EventDispatcherInterface` (default `NullEventDispatcher`), and `Psr\Clock\ClockInterface` (default `SystemClock`) identically into both `Runner` and `AsyncRunner`.
- Remove `open-telemetry/api` from `packages/runner` and forbid `OpenTelemetry` in `packages/runner/tests/ArchTest.php`. OpenTelemetry tracing is bridged via `Alama\Arazzo\Runtime\Telemetry\OtelTracer` in `packages/engine`.

### 6. Clean-Break Removals & Arch Enforcement
- Delete legacy facades (`RunnerFacade*`, `RunnerGraphBuilder*`, `AsyncGraphSeams`, `AsyncExecutionGraph`, `OperationRuntime`, `ProtocolExecutorRegistryInterface`, `Execution\Interfaces\OpenApiExecutorInterface`, `UnifiedStepCarrier`, `ResponseValidatorDispatcher`, `ResumeCorrelationJob`, `EventLedgerInterface`, `LedgerEventListener`).
- Enforce all boundaries in `packages/runner/tests/ArchTest.php` and `tests/Architecture/InternalApiTest.php`.

## Consequences

### Positive
- `php-arazzo/arazzo-runner` is protocol-agnostic, transport-free, and vendor-agnostic (zero HTTP, cache, OpenAPI, or OpenTelemetry dependencies).
- Standalone execution is concise (`Runner::create()->withExecutor($http->executor())->run($doc, 'workflowId')`) with optional per-run `ExecutionPolicy`.
- Durable hosts bind two clean persistence ports (`withStore()` + `withDefinitions()`) plus orthogonal `withQueueDriver()` / `withLockManager()` instead of five fragmented persistence registries.
- Multi-API workflows enforce strict source-description trust boundaries (`SourcePolicy` + redacted `Credential`).
- Both sync and async runners share uniform PSR-3 logging, PSR-14 events, PSR-20 clocks, and vendor-agnostic tracing.

### Negative / Trade-offs
- Breaking change for callers referencing retired pre-1.0 facades, `Alama\Arazzo\Events\*`, `EventLedgerInterface`, or old per-port setters.
- Standalone `Runner::create()` requires registering an executor plugin (e.g. `HttpProtocol::create()->executor()`) to execute HTTP steps.
- Durable event sourcing (`ExecutionHistoryInterface`, `persist-68`) and verified snapshots (`ExecutionSnapshot`, `persist-70`) remain separate follow-up persistence milestones.
