# ADR-016: AsyncAPI channelPath Resolution and Source Normalization

## Context

Arazzo 1.1 introduced first-class asynchronous step coordination via AsyncAPI sources, allowing steps to target event channels via `channelPath` (e.g. `{$sourceDescriptions.events.url}#/channels/userSignedUp`) alongside actions `send` and `receive`.

Prior to this decision, `AsyncApiStepExecutor` used `channelPath` directly as a literal HTTP URL and rejected any reference that was not already an absolute `http(s)://` URL. `channelPath` references were never dereferenced against referenced AsyncAPI source documents, and `packages/sources` lacked an AsyncAPI normalizer plugin (Audit item N11, issue #169).

## Decision

1. **AsyncAPI Source Normalization (`packages/sources`)**:
   Introduce `AsyncApiSourceNormalizer` implementing `SourceNormalizerInterface` annotated with `#[AsSourceNormalizerPlugin(SourceType::Asyncapi)]`. It auto-detects AsyncAPI 2.x and 3.x documents via `AsyncApiVersionDetector`:
   - AsyncAPI 2.x: Maps channels from root `channels` object (keys are paths, with inline `publish` / `subscribe`).
   - AsyncAPI 3.x: Maps channels from root `channels` (keys are channel IDs, with explicit `address` and `messages`) and correlates with `operations`.
   Each channel is indexed under multiple keys: RFC JSON Pointer (`#/channels/<id>`), relative pointer (`channels/<id>`), source-qualified reference (`$sourceDescriptions.<name>.<id>`), and bare identifier.

2. **Server Fallback and Concrete Address Synthesis**:
   When normalizing or resolving a channel's concrete transport URL, server resolution follows a deterministic fallback chain:
   - Channel-scoped servers (AsyncAPI 3.x `channel.servers` or 2.x channel binding);
   - First server in the document with `http` or `https` protocol;
   - First server in the document regardless of protocol (synthesizing `http(s)://` host + port + path);
   - Document source retrieval/base URL if `servers` is omitted or empty.
   The resulting endpoint combines the server base URL with the channel address (e.g. `https://broker.example.com/v1` + `users/signed-up` → `https://broker.example.com/v1/users/signed-up`).

3. **Protocol Operation Resolver Seam (`packages/protocol-http`)**:
   Introduce `AsyncApiOperationResolver` implementing `OperationResolverInterface` in `packages/protocol-http/src/Resolver/`.
   - `supportsStep(Step $step, ArazzoDocument $document)`: Returns true if the step target defines an AsyncAPI action (`send` or `receive`).
   - `resolve(Step $step, ArazzoDocument $document)`: Dereferences `channelPath` against the referenced source description, parses channel path parameters (`in: path`), interpolates `{param}` templates into the concrete address, and returns a `ResolvedOperation` containing a `NormalizedOpenApiOperation` DTO.

4. **Executor Integration (`AsyncApiStepExecutor`)**:
   `AsyncApiStepExecutor` consumes `OperationResolverInterface` (defaulting to `AsyncApiOperationResolver::create()`).
   - `send`: Sends HTTP POST to the resolved channel address with parameters and payload.
   - `receive`: Stores the dereferenced, concrete channel address in `PendingCorrelation::$channelPath` so incoming webhook/event deliveries correlate against physical or canonical channel endpoints.
   - Backward compatibility: If `channelPath` is already an absolute `http(s)://` URL, it is accepted directly without requiring source lookup.

5. **Typed Exception Hierarchy**:
   Resolution failures throw typed exceptions:
   - `AsyncApiResolutionException` in `packages/protocol-http/src/Exceptions/` for missing sources, missing channels, unresolvable targets, or document load failures.
   - `InvalidAsyncApiSourceException` in `packages/sources/src/Exceptions/` for malformed AsyncAPI structures.

## Considered Options

- **Direct Coupling of Sources to Executor**: Injecting `SourcesInterface` directly into `AsyncApiStepExecutor`. Rejected because ADR-006 and ADR-009 standardise protocol-specific operation resolution on `OperationResolverInterface`.
- **Full AsyncAPI Protocol Bindings (MQTT/Kafka/AMQP Clients)**: Implementing protocol-native wire clients. Rejected as non-goal for issue #169; php-arazzo relies on HTTP-bridged event brokers, webhooks, and REST proxies.
- **AsyncAPI 3.x Only**: Omitting AsyncAPI 2.x support. Rejected because AsyncAPI 2.x documents remain pervasive in real-world specifications, and supporting both aligns with `OpenApi30Normalizer` / `OpenApi31Normalizer`.

## Consequences

- Workflows can reference AsyncAPI sources in `channelPath` and execute real `send` and `receive` steps against event brokers.
- Receive steps record the true resolved endpoint in pending correlations.
- Conformance fixtures and tests can validate AsyncAPI channel dereferencing offline and in integration.
