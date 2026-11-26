# Specification Extensions in php-arazzo

`php-arazzo` implements the official [Arazzo Specification](https://spec.openapis.org/arazzo/v1.0.1.html) (1.0.0 and 1.1.0). To support complex distributed workflows, multi-protocol execution (HTTP, gRPC, SOAP, GraphQL), and resilient execution patterns, `php-arazzo` provides several beyond-spec extensions.

All extensions follow a **dual syntax** convention:
- **Canonical `x-*` naming** (recommended): Aligns with standard OpenAPI / Arazzo vendor extension conventions (`type: x-invoke`, `in: x-body`).
- **Permissive bare naming**: Accepted in default permissive mode for backward compatibility (`type: invoke`, `in: body`).

In **strict conformance mode** (`arazzo validate --conformance`), all beyond-spec extensions are rejected to ensure standard portability across compliant Arazzo tooling.

---

## 1. Action Types (`ActionKind`)

In the Arazzo specification, actions on steps (`onSuccess`, `onFailure`) and workflows (`successActions`, `failureActions`) only support `end`, `goto`, and `retry` (failure only).

| Extension | Canonical | Permissive Bare | Description |
|---|---|---|---|
| Sub-Workflow Invocation | `type: x-invoke` | `type: invoke` | Triggers execution of another workflow in the document upon step success or failure, passing dynamic parameters. |

### Example
```yaml
onSuccess:
  - name: triggerNotification
    type: x-invoke
    workflowId: sendNotificationWorkflow
    parameters:
      userId: $steps.createUser.outputs.id
```

---

## 2. Parameter Locations (`ParameterIn`)

In the Arazzo specification, Parameter `in` is restricted to `path`, `query`, `header`, and `cookie`.

| Extension | Canonical | Permissive Bare | Description |
|---|---|---|---|
| Request Body Injection | `in: x-body` | `in: body` | Injects the parameter value directly into the step's request payload or targets body properties. |
| Raw Querystring | `in: x-querystring` | `in: querystring` | Passes a pre-formatted, raw query string directly to the HTTP request without individual query parameter encoding. |
| RPC Metadata | `in: x-metadata` | `in: metadata` | Supplies metadata / context headers for non-HTTP protocols (e.g. gRPC metadata, Connect headers). |
| Workflow Variable | `in: x-variable` | `in: variable` | Sets or mutates workflow-scoped state variables for subsequent steps. |

### Example
```yaml
parameters:
  - name: payload
    in: x-body
    value:
      title: {$inputs.orderTitle}
      amount: {$steps.calculateTotal.outputs.total}
  - name: traceHeader
    in: x-metadata
    value: {$inputs.traceId}
```

---

## 3. Source Description Types (`SourceType`)

In Arazzo 1.0, source types are `openapi` and `arazzo` (with `asyncapi` added in 1.1). `php-arazzo` supports multi-protocol API contracts:

| Extension Type | Description |
|---|---|
| `wsdl` | WSDL 1.1 / 2.0 XML service definition for SOAP operations. |
| `protobuf` | Protocol Buffers (`.proto`) descriptor for gRPC, Connect, or Twirp services. |
| `graphql` | GraphQL schema definition or introspection endpoint. |

### Example
```yaml
sourceDescriptions:
  - name: legacyBilling
    url: ./contracts/billing.wsdl
    type: wsdl
  - name: userService
    url: ./contracts/user.proto
    type: protobuf
```

---

## 4. Step-Level `x-` Attributes

These step attributes are native `x-` specification extensions:

| Extension Property | Type | Default | Description |
|---|---|---|---|
| `x-strict-validation` | `boolean` | `true` | Validates HTTP responses against the declared OpenAPI response schema (ADR-001). Set `false` to opt out. |
| `x-idempotency-key` | `boolean` | `false` | When `true`, automatically injects a deterministic idempotency UUID header. |
| `x-idempotency-header`| `string` | `"Idempotency-Key"` | Customizes the header name used for idempotency keys (e.g. `X-Adyen-Idempotency-Key`). |

---

## 5. Conformance Mode vs. Permissive Mode

| Setting | Permissive Mode (Default) | Conformance Mode (`--conformance`) |
|---|---|---|
| **CLI Flag** | `arazzo validate doc.yaml` | `arazzo validate doc.yaml --conformance` |
| **`ValidatorPolicy`** | `ValidatorPolicy::default()` (`conformance: false`) | `ValidatorPolicy::default()->withConformance(true)` |
| **Bare Extensions** | ✅ Allowed (`invoke`, `in: body`) | ❌ Rejected (`step.parameter_in_valid`, `action.type_valid`) |
| **`x-*` Enum Extensions**| ✅ Allowed (`x-invoke`, `in: x-body`) | ❌ Rejected (strict closed enum enforcement) |
| **`x-*` Step Attributes** | ✅ Allowed | ✅ Allowed (standard specification extensions) |
