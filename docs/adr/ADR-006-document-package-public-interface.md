# ADR-006: Document Package Public Interface and Preflight Boundary

## Status

Accepted

## Context

The `alama/arazzo-document` package was originally designed with `ModelStack` acting as a composition container for parser, validator, and expression engine components, while `DocumentInterface` acted as a broad facade exposing parsing, validation, operation resolution, and preflight checks.

This structure exhibited multiple coupling and design issues:
1. `ModelStack` was a passive data bag that passed dependencies around without providing encapsulation or behavior.
2. `DocumentInterface` and `ModelStack` leaked outward by depending on `Alama\Arazzo\Sources\*` types (`PreflightValidator`, `OpenApiOperationResolverInterface`), causing an architectural cycle where `document` referenced `sources` even though `document/composer.json` cannot depend on `sources`.
3. `DocumentInterface::resolveOperation()` was dead public surface (uncalled outside of internal document classes), while protocol-specific resolution already lived across `protocol-http`, `protocol-soap`, and `protocol-rpc`.
4. Preflight validation was awkwardly positioned in `sources`, combining generic step resolution, protocol-specific OpenAPI checks, and pure JSON schema input validation.
5. `Validator` was marked `@internal`, yet `cli` and other consumers bypassed `DocumentInterface` and instantiated `Validator` and `RuleSet` directly because `DocumentInterface::validate()` had no typed mechanism to receive known resolved operations.

ADR-002 and earlier split specifications proposed keeping `DocumentInterface` monolithic. Experience showed this preserved artificial coupling and forced consumers to take dependencies on unused sub-systems.

## Decisions

1. **Retire `ModelStack`**:
   `ModelStack` is completely removed. `Document` becomes a `final readonly class` with a private constructor and a zero-argument static factory `Document::create()`. Dependencies (`Loader`, `Parser`, `Validator`, `ExpressionEngine`) are wired internally.

2. **Purify `DocumentInterface`**:
   `DocumentInterface` is focused strictly on Arazzo spec document concerns:
   - `load(string $path): ArazzoDocument`
   - `loadFromYaml(string $content, string $virtualPath = 'inline.yaml'): ArazzoDocument`
   - `loadFromJson(string $content, string $virtualPath = 'inline.json'): ArazzoDocument`
   - `validate(ArazzoDocument $document, ?KnownOperations $operations = null): ValidationResult`
   - `validateInputs(ArazzoDocument $document, string $workflowId, array $inputs): ValidationResult`

   `withDecodePolicy()` is removed from `DocumentInterface` and placed exclusively on the concrete `Document` builder. `parse(RawDocument)` is retired from the public interface.

3. **Pure Input Validation in Document**:
   Workflow input validation (`validateInputs`) relies solely on JSON Schema 2020-12 and the Arazzo AST. It does not perform I/O or require sources. It is retained directly on `DocumentInterface`.

4. **Migrate Preflight to Runner**:
   Step preflight validation is an execution-readiness gate specific to `runner`. `PreflightValidator` and `PreflightInterface` are removed from `sources` and `document`. Runner introduces `Alama\Arazzo\Runner\Preflight\Preflight`, which evaluates steps using registered `OperationResolverInterface` instances. Skipping preflight is controlled via a boolean flag (`bool $preflight = true` on `RunControlFlow`).

5. **Relocate `PreflightFailureException`**:
   `PreflightFailureException` is moved to `Alama\Arazzo\Runner\Exceptions\PreflightFailureException`.

6. **Consolidate Operation Resolution SPI**:
   `Alama\Arazzo\Sources\Normalizer\OpenApiOperationResolverInterface` is deleted. Protocol resolvers across `sources` (OpenAPI), `protocol-soap` (WSDL), and `protocol-rpc` (Protobuf) implement the single protocol-agnostic contract `Alama\Arazzo\Document\OperationResolverInterface`.

7. **Introduce `KnownOperations` Value Object**:
   The untyped `array<string, array<string, true>>` passed to `Validator` is replaced with an immutable value object `Alama\Arazzo\Document\KnownOperations`. `SourceLoader::knownOperations()` produces this object for `cli` and validation runners.

8. **Hardened Decoders SPI**:
   `DecoderInterface` and `DecodePolicy` remain public. A factory `Decoders::for(Format $format, ?DecodePolicy $policy = null)` is provided. Concrete decoders (`SymfonyYamlDecoder`, `NativeJsonDecoder`) are marked `@internal`.

9. **Architecture Enforcement via Arch Tests**:
   A Pest architecture test in `packages/document/tests/ArchTest.php` enforces that external packages only import explicitly allow-listed symbols from `Alama\Arazzo\Document\*`.

## Consequences

### Positive
- `alama/arazzo-document` has zero dependencies on `sources` or external HTTP/transport clients.
- Circular dependencies and architectural smell layers are eliminated.
- Clean, focused public API with an explicit allow-list protected by architecture tests.
- CLI and tests no longer need to construct internal validator and rule-set objects.
- Multi-protocol operation resolution is unified under a single SPI (`OperationResolverInterface`).

### Negative / Trade-offs
- Breaking change for any code directly constructing `ModelStack`, `OpenApiOperationResolverInterface`, or accessing `DocumentInterface::preflight()` / `resolveOperation()`.
- `runner` continues to depend on `sources` for source fetching during preflight and execution.
- Custom expression engine selector plugins cannot be injected into `Document::create()` (documented as a future extension point if ever needed).
