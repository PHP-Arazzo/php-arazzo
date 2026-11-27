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
Rather than creating fragmented child policy classes (`HttpExecutionPolicy`, `RateLimitPolicy`), the existing `HttpPolicy` value object is enriched to be the single canonical configuration object for `protocol-http`, conforming to ADR-010 (`final readonly`, typed `DEFAULT_*` constants, `::default()`, immutable `with*()` methods):
- **Validation**: `strictValidation` (`DEFAULT_STRICT_VALIDATION = true`).
- **Idempotency**: `idempotencyInjector`.
- **Rate-Limiting & Concurrency**:
  - `burst` (`DEFAULT_BURST = 1`)
  - `maxConcurrent` (`DEFAULT_MAX_CONCURRENT = 4`)
  - `respectRetryAfter` (`DEFAULT_RESPECT_RETRY_AFTER = true`)
  - `rateLimitScope` (`RateLimitScope::Host`)
  - `failFast` (`DEFAULT_FAIL_FAST = false`)
- **Safety Bounds (Issue #374)**:
  - `maxRedirects`
  - `maxResponseBytes`
  - `executionTimeoutSeconds`
  - `allowedSchemes` & `deniedHosts` (SSRF protection)

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
