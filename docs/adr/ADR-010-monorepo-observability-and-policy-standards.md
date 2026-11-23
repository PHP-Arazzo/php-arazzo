# ADR-010: Monorepo Observability Tiers and `*Policy` Value-Object Standards

## Status

Accepted

## Context

An audit across all 12 packages in `php-arazzo` exposed two cross-cutting inconsistencies that affect every package's public interface:

1. **Inconsistent Observability & Vendor Coupling**:
   - `packages/expression` declared `psr/log` and `psr/event-dispatcher` in `composer.json` while using neither.
   - `packages/evaluation` and `packages/engine` declared `psr/log` without using it.
   - `packages/sources`, `packages/protocol-soap`, and `packages/protocol-rpc` had zero observability hooks despite performing network I/O.
   - `packages/runner` hardcoded static `OtelSetup::getTracer()` in `StepExecutionWorker` (coupling `runner` to `open-telemetry/api` and leaving sync execution untraced) while `packages/cli` called `OtelSetup::getTracer()` directly.
2. **Inconsistent Configuration / Options Naming**:
   - `packages/document` established a strong immutable value-object pattern (`DecodePolicy`, `YamlPolicy`: `final readonly`, `DEFAULT_*` constants, `::default()`, immutable `with*()` methods), while other packages used ad-hoc `*Options` (`InterpolationOptions`), mutable internal `*Policy` (`RetryPolicy`), or loose arrays / scattered scalar setters.

## Decisions

### 1. Three-Tier Monorepo Observability Standard

Every package in `php-arazzo` belongs to exactly one observability tier:

| Tier | Packages | Required / Allowed Seams | Rationale |
|---|---|---|---|
| **Tier 1 — Pure CPU** | `document`, `expression` | **None** (zero `psr/log`, zero `psr/event-dispatcher`, zero tracing) | Pure deterministic input→output transforms; callers instrument around them. |
| **Tier 2 — I/O & Protocol** | `sources`, `protocol-http`, `protocol-soap`, `protocol-rpc` | Optional PSR-3 `LoggerInterface` (default `NullLogger`) + vendor-agnostic `TracerInterface` (default `NullTracer`) with W3C context propagation on outbound requests | Network boundaries need structured logs, latency spans, and traceparent propagation without coupling to any telemetry SDK. |
| **Tier 3 — Orchestration & Evaluation** | `runner`, `evaluation` | PSR-14 `EventDispatcherInterface` (typed domain events) + PSR-3 `LoggerInterface` + vendor-agnostic `TracerInterface` + PSR-20 `ClockInterface` | Lifecycle facts drive metrics (`obs-39`), `trace.v1` (`#364`), and durable `ExecutionHistory` (`persist-68`) via listeners; spans and logs cover runtime diagnostics. |

### 2. Vendor-Agnostic Tracing Contract & OpenTelemetry Quarantine
- `packages/contracts/src/Observability/` owns the minimal vendor-agnostic tracing primitives:
  - `TracerInterface` (`startSpan()`, `injectContext()`, `extractContext()`)
  - `SpanInterface` (`setAttribute()`, `addEvent()`, `recordException()`, `setStatus()`, `end()`)
  - `NullTracer` & `NullSpan` (zero-overhead defaults)
- `packages/contracts/src/Clock/SystemClock` provides the default PSR-20 `ClockInterface` implementation.
- **OpenTelemetry quarantine**: `open-telemetry/*` packages are forbidden in `contracts`, `document`, `expression`, `evaluation`, `sources`, `protocol-*`, and `runner`. OpenTelemetry bindings live behind `Alama\Arazzo\Runtime\Telemetry\OtelTracer` (wrapping `OtelSetup` and `TraceContextPropagator` in `packages/engine`, extractable to a standalone telemetry adapter package later without breaking callers).

### 3. Monorepo-Wide `*Policy` Value-Object Standard
- All public immutable rule, budget, security, and behavior configuration DTOs across the monorepo adopt the **`*Policy` suffix** and follow the `DecodePolicy` idiom:
  - `final readonly class <Domain>Policy`
  - Typed `DEFAULT_*` constants where applicable
  - `public static function default(): self`
  - Immutable `with*(): self` builder methods
  - Avoid `*Options`, `*Settings`, or `*Config` suffixes on public APIs.
- **Strict secret handling**: Credentials carried inside policies (e.g. `Alama\Arazzo\Contracts\Policy\Credential` inside `SourcePolicy`) MUST use `#[SensitiveParameter]` on constructors/factories, redact secrets in `__debugInfo()`, and remain excluded from serialized execution state (`toArray()`).

### 4. Incremental Rollout Status
- **Completed**:
  - `packages/contracts`: `Observability\{TracerInterface, SpanInterface, NullTracer, NullSpan}`, `Clock\SystemClock`, `Policy\{SourcePolicy, Credential, CredentialType, ServerTarget, SourceTransportPolicy}`, retired `EventLedgerInterface` and `StepProtocolExecutorInterface`.
  - `packages/engine`: `OtelTracer` / `OtelSpan` quarantine, `RetryPolicy` aligned to `final readonly` + `::default()` + immutable `with*()` + PSR-20 `ClockInterface`, stripped unused `psr/log`.
  - `packages/runner` & `packages/cli`: `ExecutionPolicy`, `ExecutionBudgets`, full PSR-3/PSR-14/PSR-20/`TracerInterface` wiring, removed `open-telemetry/api` from `runner`, wired `OtelTracer` in `cli`.
  - `packages/expression` & `packages/evaluation`: stripped unused `psr/log` & `psr/event-dispatcher`, renamed `InterpolationOptions` → `InterpolationPolicy`, enforced Tier-1 `ArchTest` guards.
  - `packages/sources`: added `FetchPolicy` and Tier-2 `LoggerInterface` / `TracerInterface` with W3C `traceparent` injection.
  - `packages/protocol-http`, `packages/protocol-soap`, `packages/protocol-rpc`: added `HttpPolicy`, consumed `WorkflowContext::getActiveSourcePolicy()` (`SourcePolicy`), and wired Tier-2 `LoggerInterface` / `TracerInterface` with W3C `traceparent` injection.
- **Future**: extract OpenTelemetry adapters (`OtelTracer` / `OtelSetup`) from `packages/engine` into a dedicated telemetry adapter package when standalone telemetry packaging is prioritized.

## Consequences

### Positive
- Every package has an unambiguous, enforceable rule for which observability dependencies it may declare.
- Swapping OpenTelemetry for Datadog, Sentry, or in-memory test tracers requires zero changes in `runner` or protocol packages.
- Users encounter one consistent immutable `*Policy` pattern across `Document`, `Runner`, `Sources`, `Expression`, and protocols.

### Negative / Trade-offs
- Existing packages outside `contracts`/`runner`/`cli` still carry legacy observability or naming drift until their follow-up PRs land.
- `TracerInterface` intentionally exposes a minimal span lifecycle; backends needing advanced OTel-specific features (links, custom samplers, batch processors) configure those inside the `OtelTracer` / `OtelSetup` adapter layer.
