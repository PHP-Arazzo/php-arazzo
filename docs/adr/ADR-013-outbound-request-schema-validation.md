# ADR-013: Outbound Request Schema Validation and RequestValidatorInterface

## Status

Accepted

## Date

2026-11-26

## Context

php-arazzo validates inbound responses against OpenAPI response schemas via `ResponseSchemaValidator` (ADR-001), but previously did not validate outbound HTTP requests before dispatch. A step could compile and dispatch an invalid request body or off-schema parameter types (`path`, `query`, `header`, `cookie`), resulting in remote server errors, unpredictable remote side effects, or silent data corruption. Issue #333 defines pre-dispatch validation against the OpenAPI operation schema.

## Decisions

1. **`RequestValidatorInterface` Seam in `contracts`**:
   Introduce `Alama\Arazzo\Contracts\Interfaces\RequestValidatorInterface` in `packages/contracts`:
   ```php
   public function validateRequest(
       Step $step,
       ResolvedOperation $operation,
       OpenApiPayload $payload,
       ?ArazzoDocument $document = null,
   ): void;
   ```
   Passing the compiled `OpenApiPayload` encapsulates `$path`, `$query`, `$header`, `$cookie`, `$body`, and `$bodyMediaType` without unwieldy multi-argument signatures.

2. **Full OpenAPI Contract Enforcement**:
   Request validation validates both schema conformity of provided values and presence of required components:
   - Declared parameters with `required: true` MUST be present in the resolved payload.
   - Declared `requestBody` with `required: true` MUST have a non-null payload.
   - Provided parameter values and request bodies are validated against their respective OpenAPI 3.0 / 3.1 schemas using `SchemaValidator`.

3. **Pre-Validation Parameter Routing and Type Coercion**:
   In `HttpStepExecutor`, auto-parameters (`in: auto`) are routed into concrete `path`, `query`, `header`, and `cookie` buckets prior to validation. Parameters undergo schema-directed scalar type casting via `TypeCaster` before schema inspection. Valid representations (e.g. `"123"` for an `integer` query parameter) are coerced and pass validation; non-coercible inputs (e.g. `"abc"` for `integer`) remain strings and fail validation with a type violation.

4. **Unified Strict Validation Policy**:
   Outbound request validation is governed by the existing `strictValidation` policy (defaulting to `true` per ADR-001). Steps may opt out explicitly with `x-strict-validation: false`. When strict validation is disabled, pre-dispatch request validation is bypassed.

5. **Canonical JSON Pointer Format & Exception Context**:
   Violations throw `SchemaValidationException::forRequestStep(string $stepId, array $violations)`. Pointers in violations follow standardized prefixes:
   - Parameter values and missing required parameters: `/parameters/{in}/{name}` (e.g. `/parameters/query/id`, `/parameters/path/itemId`, `/parameters/header/X-Trace`, `/parameters/cookie/session`)
   - Missing required request body: `/body`
   - Request body properties: `/body/{jsonPointer}` (e.g. `/body/name`, `/body/items/0/id`, or `/body` for root payload type mismatch)

6. **Class Placement and Seam Wiring**:
   `RequestSchemaValidator` is implemented in `packages/protocol-http/src/Execution/RequestSchemaValidator.php`. It is injected into `HttpStepExecutor` via an optional constructor parameter (`?RequestValidatorInterface $requestValidator = null`) defaulting to `new RequestSchemaValidator()`, and registered in `HttpProtocol::executor()`.

## Consequences

### Positive
- Prevents malformed or schema-violating requests from being dispatched over the network.
- Deterministic, local failure reporting with precise JSON pointers before any transport side effects occur.
- Reuses the existing `SchemaValidator` engine without duplicating JSON Schema logic.
- Fully backwards-compatible constructor signature for `HttpStepExecutor` preserving existing tests and custom harnesses.

### Negative / Trade-offs
- Strict validation is enabled by default; existing workflows with off-schema outbound payloads that previously relied on server leniency will fail locally unless opted out with `x-strict-validation: false`.
