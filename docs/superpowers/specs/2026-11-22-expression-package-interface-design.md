# Design Spec: Expression Package Public Interface and Unified Plugin Registry Architecture

## 1. Intent & Context

The `alama/arazzo-expression` package is responsible for Arazzo expression syntax parsing, reference projection, string interpolation, and selector evaluation (JSONPath, XPath, JSON Pointer).

During the architectural audit following the redesign of `alama/arazzo-document` (ADR-006), several boundary defects were identified in `alama/arazzo-expression` and across the monorepo's registry implementations:
1. **Leaked Internal Registry**: `ExpressionEngineInterface::selectorPlugins()` returned `SelectorPluginRegistryInterface`, exposing an internal strategy dispatch registry to all consumers.
2. **Redundant Registry Interfaces**: `SelectorPluginRegistryInterface` and `SourceNormalizerRegistryInterface` were declared as interfaces despite only ever having a single concrete implementation in their respective packages.
3. **Downstream Package Coupling**: `packages/document` and `packages/evaluation` accepted `SelectorPluginRegistryInterface` in their constructors/factories (`RuleSet::default`, `EvaluationEngine::__construct`, `CriteriaEvaluator::__construct`, `BuiltInCriterionEvaluator`, `PayloadReplacer`), requiring external packages to know about expression internals.
4. **Test Double Boilerplate**: Tests in other packages providing doubles for `ExpressionEngineInterface` had to implement dummy classes for `SelectorPluginRegistryInterface` solely to satisfy `selectorPlugins()`.
5. **Missing Primitives on Public Facade**: `ExpressionEngineInterface` offered `jsonPath()` and `jsonPointer()`, but omitted `xpath()` and had no general raw-data selector evaluator, forcing `evaluation` to reach for the registry.
6. **Cross-Monorepo Inconsistency & Manual Registration Wiring**: Registries across the monorepo (`SourceNormalizerRegistry`, `OperationExecutorRegistry`, `ExpressionEvaluatorRegistry`, `CriterionEvaluatorRegistry`) required manual procedural registration and constructor parameter drilling, while run-scoped document caches (`SourceRegistry`) were confusingly labeled as "Registries".

This design establishes a clean public interface for `alama/arazzo-expression`, unifies **all** plugin registries across the monorepo onto a concrete singleton `create()` architecture, standardizes **all** plugins on a declarative PHP 8 Attribute discovery model, and decouples downstream packages.

---

## 2. Settled Decisions (Design Tree)

### 2.1 The Universal Plugin Attribute Architecture
**Every plugin in the PHP-Arazzo ecosystem will declare its registration metadata using dedicated PHP 8 attributes.**
All plugin attributes live in `packages/contracts/src/Attributes/`:
- `#[AsSelectorPlugin(ExpressionType $type, int $priority = 0)]`
- `#[AsSourceNormalizer(SourceType $type, int $priority = 0)]`
- `#[AsOperationExecutor(int $priority = 0)]`
- `#[AsCriterionPlugin(CriterionType|string $type, int $priority = 0)]`
- `#[AsExpressionPlugin(int $priority = 0)]`

Registries read these attributes via reflection during `register(object $plugin)`:
- Callers/registrars simply pass the plugin instance without having to manually duplicate types, enums, or priorities in method parameters.
- Built-in default plugins in every package are decorated with their respective attributes.
- Framework adapters (`laravel`, `cli`) use container scanning to discover user-defined or package-provided plugins with these attributes and register them into the core singletons.

### 2.2 Phase 1: Expression Package & Public Seam
- **Primary Seam**: `ExpressionEngineInterface` is the sole external contract for expression parsing, reference projection, and selector execution.
- **Factory**: `ExpressionEngine::create()` zero-argument static factory.
- **Added Capabilities on `ExpressionEngineInterface`**:
  - `evaluateRawSelector(ExpressionType $type, string $expression, mixed $data, ?string $version = null, ?array $namespaces = null): mixed` (throws `SelectorEvaluationException` if no plugin registered).
  - `xpath(string $expression, mixed $data, ?string $version = null, ?array $namespaces = null): mixed`.
  - `supportedSelectorVersions(ExpressionType $type): array`.
- **Removed from Seam**: `selectorPlugins()` is deleted from `ExpressionEngineInterface` and `ExpressionEngine`.
- **Concrete Singleton `SelectorPluginRegistry`**: `final class SelectorPluginRegistry` with `private __construct()`, `create(): self`, and `reset(): void` (for test isolation). Default plugins are registered on first creation.
- **Delete `SelectorPluginRegistryInterface`**: Deleted completely from the codebase.
- **Public Extension Point**: `SelectorPluginRegistry` and `SelectorPluginInterface` are public. Default implementations (`JsonPathSelectorPlugin`, `JsonPointerSelectorPlugin`, `XpathSelectorPlugin`) stay `@internal` and declare `#[AsSelectorPlugin]`.
- **Downstream Decoupling**:
  - `document` (`RuleSet` and tests) drops `$selectorRegistry` and queries `$engine->supportedSelectorVersions(ExpressionType::JsonPath)`.
  - `evaluation` (`EvaluationEngine`, `CriteriaEvaluator`, `BuiltInCriterionEvaluator`, `PayloadReplacer`) drops `$selectorRegistry` and uses `$expression->xpath()` and `$expression->jsonPath()`.

### 2.3 Phase 2: Cross-Monorepo Registry Unification
- **`SourceNormalizerRegistry`**:
  - Delete `SourceNormalizerRegistryInterface` from `packages/contracts`.
  - Promote and move `SourceNormalizerRegistry` to `Alama\Arazzo\Sources\Registries\SourceNormalizerRegistry` as a concrete singleton with `create(): self` (auto-seeding zero-vendor `OpenApiSourceNormalizer`) and `reset(): void`.
  - Decorate `OpenApiSourceNormalizer`, `WsdlSourceNormalizer` (in `protocol-soap`), and `ProtoSourceNormalizer` with `#[AsSourceNormalizer]`.
  - Protocol registrars register directly into `SourceNormalizerRegistry::create()`.
  - `Sources` checks per-instance `withNormalizer()` overrides first and falls back to `SourceNormalizerRegistry::create()->get($type)`.
- **`OperationExecutorRegistry`**:
  - Convert `OperationExecutorRegistry` into a concrete singleton with `create(): self` and `reset(): void`.
  - Decorate `HttpStepExecutor`, `AsyncApiStepExecutor`, `RpcOperationExecutor`, `SoapOperationExecutor`, `SubWorkflowStepExecutor` with `#[AsOperationExecutor]`.
  - `ExecutionGraphFactory`, `StepExecutor`, `CliRunner` use `create()` directly.
- **`ExpressionEvaluatorRegistry` & `CriterionEvaluatorRegistry`**:
  - Convert both into concrete singletons with `create(): self` and `reset(): void`.
  - Decorate `JsonPathExpressionPlugin` and `JsonPathCriterionPlugin` with `#[AsExpressionPlugin]` and `#[AsCriterionPlugin]`.
  - Remove `$expressionRegistry` and `$criterionRegistry` from `EvaluationEngine::__construct()`, encapsulating them inside `EvaluationEngine`.
- **Eliminate `SourceRegistry`**:
  - Delete `SourceRegistry` decorator entirely.
  - Move document memoization (`$cache`), explicit document seeding (`$seeded`), and circular-reference detection (`$resolving`) directly into `DefaultSourceResolver` (`withFetcher()` preserves `$seeded` and clears `$cache`).
  - Expose immutable in-memory document seeding publicly via `SourcesInterface::withDocument(SourceDocument $document): static`.
- **Framework Bridges**:
  - In `alama/laravel-arazzo` and `cli`, auto-tag classes annotated with `#[As...]` attributes and bind them into the core singletons.

---

## 3. Public API Surface (`alama/arazzo-expression`)

### 3.1 Primary Seam & Facade

```php
namespace Alama\Arazzo\Expression\Interfaces;

use Alama\Arazzo\Contracts\Spec\Enum\ExpressionType;
use Alama\Arazzo\Contracts\Spec\Enum\SpecVersion;
use Alama\Arazzo\Contracts\Spec\Expression;
use Alama\Arazzo\Contracts\Spec\Interfaces\WorkflowContextInterface;
use Alama\Arazzo\Contracts\Spec\Selector;
use Alama\Arazzo\Expression\Data\EmbeddedSegment;
use Alama\Arazzo\Expression\Data\ExpressionReference;
use Alama\Arazzo\Expression\Data\InterpolationOptions;
use Alama\Arazzo\Expression\Exceptions\ExpressionSyntaxException;
use Alama\Arazzo\Expression\Exceptions\SelectorEvaluationException;

interface ExpressionEngineInterface
{
    public function parseExpression(string $raw): ?ExpressionSyntaxException;

    public function expressionReferences(string $raw): ?ExpressionReference;

    /** @return list<string> */
    public function extract(string $template): array;

    /** @return list<EmbeddedSegment> */
    public function parseEmbedded(string $raw, SpecVersion $version = SpecVersion::V1_0): array;

    public function interpolate(string $template, callable $resolver, array $options = []): string;

    public function interpolateWithOptions(string $template, callable $resolver, ?InterpolationOptions $options = null): string;

    public function test(string $raw): bool;

    public function evaluate(Expression $expression, EvaluationInputInterface $context): mixed;

    public function evaluateSelector(Selector $selector, WorkflowContextInterface $context, string $stepId): mixed;

    public function evaluateRawSelector(
        ExpressionType $type,
        string $expression,
        mixed $data,
        ?string $version = null,
        ?array $namespaces = null,
    ): mixed;

    /** @param array<array-key, mixed>|object $data */
    public function jsonPath(string $expression, array|object $data): mixed;

    /** @param array<array-key, mixed> $data */
    public function jsonPointer(array $data, ?string $pointer): mixed;

    public function xpath(
        string $expression,
        mixed $data,
        ?string $version = null,
        ?array $namespaces = null,
    ): mixed;

    /** @return list<string> */
    public function supportedSelectorVersions(ExpressionType $type): array;
}
```

```php
namespace Alama\Arazzo\Expression;

final readonly class ExpressionEngine implements ExpressionEngineInterface
{
    public function __construct() { ... }

    public static function create(): self
    {
        return new self();
    }

    // ... implements all ExpressionEngineInterface methods
}
```

### 3.2 Selector Plugin SPI & Registry

```php
namespace Alama\Arazzo\Expression\Registries;

use Alama\Arazzo\Contracts\Spec\Enum\ExpressionType;
use Alama\Arazzo\Expression\Interfaces\SelectorPluginInterface;

final class SelectorPluginRegistry
{
    private function __construct() { ... }

    public static function create(): self;

    public static function reset(): void;

    public function register(
        ExpressionType|SelectorPluginInterface $typeOrPlugin,
        ?SelectorPluginInterface $plugin = null,
        int $priority = 0,
    ): void;

    public function get(ExpressionType $type): ?SelectorPluginInterface;

    public function has(ExpressionType $type): bool;

    /** @return list<string> */
    public function supportedVersions(ExpressionType $type): array;

    /** @return list<SelectorPluginInterface> */
    public function all(): array;
}
```

```php
namespace Alama\Arazzo\Expression\Interfaces;

use Alama\Arazzo\Contracts\Spec\Enum\ExpressionType;

interface SelectorPluginInterface
{
    public function supports(ExpressionType $type): bool;

    public function evaluate(mixed $rootValue, string $expression, ?string $version, ?array $namespaces = null): mixed;

    /** @return list<string> */
    public function supportedVersions(): array;
}
```

### 3.3 Public Value Objects and Exceptions
- `Alama\Arazzo\Expression\Data\ExpressionReference` (`final readonly class`)
- `Alama\Arazzo\Expression\Data\EmbeddedSegment` (`final readonly class`)
- `Alama\Arazzo\Expression\Data\InterpolationOptions` (`final readonly class`)
- `Alama\Arazzo\Expression\Exceptions\ExpressionSyntaxException` (`class`)
- `Alama\Arazzo\Expression\Exceptions\SelectorEvaluationException` (`class`)

---

## 4. Universal Attribute Schema (`packages/contracts`)

All attribute definitions are marked `#[Attribute(Attribute::TARGET_CLASS)]`:

```php
namespace Alama\Arazzo\Contracts\Attributes;

use Alama\Arazzo\Contracts\Spec\Enum\ExpressionType;
use Alama\Arazzo\Contracts\Spec\Enum\SourceType;
use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class AsSelectorPlugin
{
    public function __construct(
        public ExpressionType $type,
        public int $priority = 0,
    ) {}
}

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class AsSourceNormalizer
{
    public function __construct(
        public SourceType $type,
        public int $priority = 0,
    ) {}
}

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class AsOperationExecutor
{
    public function __construct(
        public int $priority = 0,
    ) {}
}

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class AsCriterionPlugin
{
    public function __construct(
        public string $type,
        public int $priority = 0,
    ) {}
}

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class AsExpressionPlugin
{
    public function __construct(
        public int $priority = 0,
    ) {}
}
```

---

## 5. Downstream Decoupling Specification

### 5.1 `packages/document`
- **`RuleSet::default`**:
  ```php
  public static function default(
      ExpressionEngineInterface $engine,
      array $disabled = [],
      bool $strict = true,
  ): self {
      return new self([
          // ...
          new SelectorTypeSupportedRule($engine->supportedSelectorVersions(ExpressionType::JsonPath)),
          // ...
      ], $disabled, $strict);
  }
  ```
- **Test Stubs**: Test files in `packages/document/tests/Validator/Rules/` that mock `ExpressionEngineInterface` remove all implementations of `selectorPlugins()`.

### 5.2 `packages/evaluation`
- **`EvaluationEngine`**:
  ```php
  public function __construct(
      private readonly ExpressionEngineInterface $expression,
      ?EventDispatcherInterface $eventDispatcher = null,
  )
  ```
- **`CriteriaEvaluator`**:
  ```php
  public function __construct(
      private ExpressionEngineInterface $expression,
      ?ConditionEvaluator $conditionEvaluator = null,
      ?EventDispatcherInterface $eventDispatcher = null,
  )
  ```
- **`BuiltInCriterionEvaluator`**:
  Takes `ExpressionEngineInterface $expression`.
  - JSONPath: `$this->expression->jsonPath($criterion->condition, $root)`.
  - XPath: `$this->expression->xpath($criterion->condition, $root, $version)`.
- **`PayloadReplacer`**:
  - In `applySelectorTarget`: delegates XPath directly to `$expression->xpath($replacement->target, $body, 'xpath-10')`.
