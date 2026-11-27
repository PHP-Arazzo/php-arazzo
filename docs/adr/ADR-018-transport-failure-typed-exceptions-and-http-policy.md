# ADR-018: Transport Failure Typed Exceptions and Unified HttpPolicy

## Status

Accepted

## Context

Transport failures and protocol errors were historically masked in `packages/runner` and `packages/protocol-http` by catching generic `\Throwable` instances and fabricating synthetic HTTP 500 responses (`StepExecutionOutcome::resolved(500, [], ['error' => $e->getMessage()], failureCategory: 'transport')`). 

This pattern had severe drawbacks:
1. **Masked Failure Causality**: DNS failure, network timeouts, connection resets, and invalid TLS certificates appeared indistinguishable from real upstream HTTP 500 internal server responses.
2. **Polluted Criteria Evaluation**: Step `successCriteria` evaluation was forced to run against synthetic HTTP status codes and fake bodies.
3. **Missing Typed Contracts**: Callers and workflow orchestrators could not catch or inspect underlying typed errors (`TransportException`, `ProtocolException`, etc.).
4. **Policy Fragmentation Risk**: Outbound safety limits (rate-limiting, concurrency, redirect bounds, response byte caps, SSRF guards) lacked a unified design, risking ad-hoc options classes or scattered configuration.

## Decisions

### 1. Root Typed Exception Hierarchy (`packages/contracts/src/Exceptions/`)
Define a first-class execution exception hierarchy in `packages/contracts/src/Exceptions/` so protocol packages, the engine, and the runner can throw and handle them without circular or inverted package dependencies:
- `ArazzoExecutionExceptionInterface`: Marker interface for all execution-phase exceptions.
- `TransportException`: Network I/O, DNS resolution, connection timeout, socket reset, or client-level transport failure.
- `ProtocolException`: Protocol-level framing errors, invalid wire payloads, unexpected content-type mismatches, or malformed responses.
- `CriteriaException`: Evaluated workflow or step success criteria violation.

Existing validation exceptions (`SchemaValidationException`, `PreflightFailureException`) remain integrated under this classification taxonomy.

### 2. Elimination of Synthetic 500 Responses via `StepExecutionOutcome::failed()`
Remove all synthetic `500` status codes across executors:
- `StepExecutionOutcome` in `packages/contracts/src/Spec/StepExecutionOutcome.php` gains a typed failure constructor:
  ```php
  public static function failed(
      \Throwable $exception,
      string $failureCategory,
      ?array $request = null,
      array $responseHeaders = [],
      ?string $rawBody = null,
      ?string $contentType = null,
  ): self
  ```
- When a transport error occurs, `statusCode` is `null` (never fabricated as 500).
- The captured `$request` is retained for tracing, logging, and retry analysis.
- `WorkflowExecutor` inspects the outcome and evaluates whether `onFailure.retry` applies to the failure category before transitioning to `onFailure.goto` or dispatching `StepFailedEvent`.

### 3. Centralized `ErrorClassifier` (`packages/engine/src/Execution/ErrorClassifier.php`)
A single domain service, `ErrorClassifier`, serves as the source of truth for error classification across all protocol executors (`protocol-http`, `protocol-rpc`, `protocol-soap`) and `StepExecutor`:
- Maps PSR-18 exceptions (`ClientExceptionInterface`, `NetworkExceptionInterface`, `RequestExceptionInterface`), Guzzle exceptions, and domain exceptions into canonical categories: `transport`, `protocol`, `criteria`, `schema`, `authoring`.

### 4. Unified `HttpPolicy` in `packages/protocol-http/src/Policy/HttpPolicy.php`
Rather than creating fragmented top-level policy classes (`HttpExecutionPolicy`, `HttpExecutionPolicyCollection`), `HttpPolicy` is the pure, immutable aggregate policy root for `protocol-http` (conforming to ADR-010: `final readonly`, typed `DEFAULT_*` constants, `::default()`, immutable `with*()` methods, zero runtime services, zero magic `__get()` forwarding, and named `InvalidHttpPolicyException` invariant checks). It composes cohesive behavioral Value Objects and supports optional single-level per-source `HttpPolicy` overrides (`withSourceOverride()`, `withoutSourceOverride()`, `forSource()`), while per-source credentials remain strictly owned by `SourcePolicy`:
- **Validation**: `strictValidation` (`DEFAULT_STRICT_VALIDATION = true`).
- **Idempotency (`IdempotencyPolicy`)**: `enabled` (`false`), `headerName` (`'Idempotency-Key'`) — pure VO replacing `@internal IdempotencyKeyInjector` on the policy surface.
- **Rate-Limiting & Concurrency (`RateLimitPolicy`)**: `requestsPerSecond` (`null`), `burst` (`1`), `maxConcurrent` (`4`), `scope` (`RateLimitScope::Host`), `failFast` (`false`). Runtime `OutboundRateLimiter` lives strictly on `HttpProtocol` (`HttpProtocol::withRateLimiter()`).
- **Safety Bounds (`OutboundSafetyPolicy` — Issue #374)**:
  - `RedirectPolicy`: `maxRedirects` (`10`), `allowDowngrade` (`false`), RFC 9110 hop handling, and cross-origin credential stripping.
  - `NetworkPolicy`: `allowedSchemes` (`['http', 'https']`), `allowedHosts` (`[]`), `deniedHosts` (`[]`), `blockLinkLocal` (`true` — `169.254.0.0/16`, `fe80::/10`, `0.0.0.0/8`, `::/128`), `blockPrivateNetworks` (`false`), enforced lexically and via injectable `HostResolver` DNS checks (fail-open on DNS lookup miss).
  - `TransferPolicy`: `maxResponseBytes` (`10 MiB`), `connectTimeout` (`10.0`), enforced via `Content-Length` fast-reject + bounded streaming read.
  - Refusals throw `HttpPolicyViolationException` (carrying stable `transport.*` error codes preserved through `TransportException::$errorCode`), emit PSR-3 warnings and tracer span events, and fail steps via `StepExecutionOutcome::failed(..., failureCategory: 'transport')`.
- **Workflow Execution Deadline (`packages/runner`)**:
  - Because `protocol-http` is strictly isolated from `runner` and executes one step at a time, cumulative workflow `executionTimeout` (`DEFAULT_EXECUTION_TIMEOUT = 300.0`, `null` to disable) lives on `ExecutionBudgets` / `ExecutionPolicy` and is enforced by `WorkflowExecutor` via `ClockInterface`, throwing `ExecutionTimeoutException` (`transport.execution_timeout`).

### 5. Implementation Sequence for Release Readiness
To unblock Epic #24 and its downstream release readiness dependencies (#22, #26, #27), implementation follows the strict MVP order:
1. **#336**: Rate-limiting and concurrency controls on `HttpPolicy`.
2. **Core #24**: Typed exception hierarchy in `contracts`, `StepExecutionOutcome::failed()`, `ErrorClassifier`, and removal of synthetic 500s.
3. **#374**: Outbound safety bounds (SSRF, redirects, byte limits) on `HttpPolicy`.
4. **#348**: Source description base URL overrides at execution time.
5. **Deferred**: #354 (mTLS client certificates) and #356 (HAR 1.2 export) deferred to subsequent releases.

## Consequences

### Positive
- Transparent, truthful execution outcomes: network failures are explicitly identifiable and never masquerade as HTTP 500 responses.
- `successCriteria` evaluation is bypassed on transport failures where no HTTP response was received.
- Single configuration touchpoint via `HttpPolicy`.
- Architectural boundaries respected: `contracts` owns cross-package types, `engine` owns classification, `protocol-http` owns HTTP policies.

### Negative / Trade-offs
- Tests that previously asserted a synthetic HTTP 500 status code on network faults must be updated to expect `statusCode: null` and `TransportException` / `failureCategory: 'transport'`.
