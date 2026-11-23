# ADR-007: Expression Package Public Interface and Plugin Registry Architecture

## Status

Accepted

## Context

The `alama/arazzo-expression` package provides runtime expression parsing, AST reference projection, string interpolation, and selector evaluation for the PHP-Arazzo ecosystem.

Prior to this decision, several architectural boundaries and coupling issues existed:
1. **Registry Leak on Public Seam**: `ExpressionEngineInterface::selectorPlugins()` leaked `SelectorPluginRegistryInterface` to all external consumers.
2. **Unnecessary Interface Overhead**: `SelectorPluginRegistryInterface` existed despite only ever having a single concrete implementation (`SelectorPluginRegistry`) in `packages/expression`.
3. **Downstream Coupling**: Downstream packages (`document` and `evaluation`) depended on `SelectorPluginRegistryInterface`:
   - `RuleSet` in `document` accepted `?SelectorPluginRegistryInterface $selectorRegistry = null` to query supported versions for validation (`SelectorTypeSupportedRule`).
   - `EvaluationEngine`, `CriteriaEvaluator`, `BuiltInCriterionEvaluator`, and `PayloadReplacer` in `evaluation` accepted `?SelectorPluginRegistryInterface $selectorRegistry` to resolve selector plugins.
4. **Test Double Pollution**: Because `ExpressionEngineInterface` demanded `selectorPlugins(): SelectorPluginRegistryInterface`, tests in other packages creating test stubs for `ExpressionEngineInterface` were forced to write boilerplate anonymous classes implementing `SelectorPluginRegistryInterface`.
5. **Asymmetry in Primitive Methods**: `ExpressionEngineInterface` exposed specific helpers `jsonPath()` and `jsonPointer()`, but omitted `xpath()` and had no generic method for raw selector evaluation against arbitrary data.
6. **Inconsistent Registry Patterns Across the Monorepo**: Similar plugin registries across other packages (`SourceNormalizerRegistry`, `OperationExecutorRegistry`, `ExpressionEvaluatorRegistry`, `CriterionEvaluatorRegistry`) lacked a consistent design pattern, while runtime-scoped caches (such as `SourceRegistry`) were confusingly named "Registry" despite storing mutable run state.

## Decisions

### 1. ExpressionEngineInterface as Sole Public Seam
All selector capabilities required by external packages are exposed directly on `ExpressionEngineInterface`:
- `public function evaluateRawSelector(ExpressionType $type, string $expression, mixed $data, ?string $version = null, ?array $namespaces = null): mixed;`
- `public function xpath(string $expression, mixed $data, ?string $version = null, ?array $namespaces = null): mixed;`
- `public function supportedSelectorVersions(ExpressionType $type): array;`

The method `selectorPlugins()` is **removed** from `ExpressionEngineInterface` and `ExpressionEngine`.

### 2. Concrete Singleton `SelectorPluginRegistry`
`SelectorPluginRegistry` is made a concrete singleton:
- `private __construct()`.
- `public static function create(): self` initializes and returns the shared singleton instance with default plugins (`JsonPathSelectorPlugin`, `JsonPointerSelectorPlugin`, `XpathSelectorPlugin`).
- `public static function reset(): void` clears the singleton for test isolation.
- Public methods: `register()`, `get()`, `has()`, `supportedVersions()`, `all()`.

### 3. Deletion of `SelectorPluginRegistryInterface`
`SelectorPluginRegistryInterface` is completely deleted. No package inside or outside `packages/expression` references it. Internal expression collaborators (`SelectorEvaluator`, `ExpressionDependencyProvider`) type-hint `SelectorPluginRegistry` directly.

### 4. Internal `SelectorPluginRegistry` & Enum-Typed Attributes
- `SelectorPluginRegistry` stays `@internal` to `packages/expression` as an internal strategy registry (other packages never import it). External extension happens via `SelectorPluginInterface` + `#[AsSelectorPlugin]` + Composer `extra.arazzo.plugins` directories.
- Default plugin implementations (`JsonPathSelectorPlugin`, `JsonPointerSelectorPlugin`, `XpathSelectorPlugin`) remain `@internal`.

### 5. Declarative Enum-Typed Plugin Attributes (No Numeric Priorities)
All 6 plugin attributes in `packages/contracts/src/Attributes/` carry typed PHP 8.4 Enums instead of numeric priorities (with last-registered-wins override semantics in registries):
- `#[AsSelectorPlugin(ExpressionType $type)]`
- `#[AsSourceNormalizer(SourceType $type)]`
- `#[AsExpressionPlugin(ExpressionType $type)]`
- `#[AsCriterionPlugin(CriterionType $type)]`
- `#[AsValidationRule(ValidationPhase $phase = ValidationPhase::Semantic)]`
- `#[AsOperationExecutor(OperationProtocol $protocol)]`

### 6. Decouple Downstream Packages (`document` and `evaluation`)
- **`packages/document`**: `RuleSet::default(ExpressionEngineInterface $engine, ...)` drops `$selectorRegistry` and queries `$engine->supportedSelectorVersions(ExpressionType::JsonPath)` directly. Test stubs in `document` remove all dummy `selectorPlugins()` methods.
- **`packages/evaluation`**: `EvaluationEngine::__construct` and `CriteriaEvaluator::__construct` drop `$selectorRegistry`. `BuiltInCriterionEvaluator` and `PayloadReplacer` take `ExpressionEngineInterface` and delegate to `$expression->xpath()` / `$expression->jsonPath()`.

### 7. Cross-Monorepo Registry Architecture (Phased Roadmap)
A clear architectural distinction is established across all monorepo packages:
- **Stateless Plugin / Strategy Registries**: Must follow the concrete singleton `create()` + `reset()` pattern without redundant interfaces:
  - `SelectorPluginRegistry` (Phase 1).
  - `SourceNormalizerRegistry` (Phase 2: promoted to `Alama\Arazzo\Sources\Registries\SourceNormalizerRegistry`, `SourceNormalizerRegistryInterface` deleted, singleton `create()` auto-seeding zero-vendor `OpenApiSourceNormalizer`, paired with per-instance `Sources::withNormalizer()` overrides).
  - `OperationExecutorRegistry` (Phase 2: singleton `create()`).
  - `ExpressionEvaluatorRegistry` & `CriterionEvaluatorRegistry` (Phase 2: singleton `create()`, encapsulated in `EvaluationEngine`).
  - `ValidationRuleRegistry` (Phase 3: `Alama\Arazzo\Document\Registries\ValidationRuleRegistry`, singleton `create()`, auto-discovering `#[AsValidationRule]` rules across `document`, `expression`, `protocol-soap`, `protocol-rpc`, and consumer apps).
- **Zero-Scan Auto-Discovery (`PluginDiscovery`) & Registrar Retirement (Phase 3)**:
  - `Alama\Arazzo\Contracts\Discovery\PluginDiscovery` reads installed packages' `composer.json` `extra.arazzo.plugins` manifests via `Composer\InstalledVersions` and filters by PHP 8 `#[As*]` attributes.
  - All 5 stateless registries (`SelectorPluginRegistry`, `SourceNormalizerRegistry`, `ValidationRuleRegistry`, `ExpressionEvaluatorRegistry`, `CriterionEvaluatorRegistry`) auto-discover plugins inside `create()`.
  - `ProtocolHttpRegistrar`, `SoapRegistrar`, and `RpcRegistrar` are deleted; framework bridges discover DI-bound executors via `PluginDiscovery::classesWith(AsOperationExecutor::class)`.
  - Validation SPI (`ValidationRuleInterface`, `SymbolTable`, `ErrorCollector`, `Error`, `Warning`, `Severity`, `ValidationResult`) is promoted to `packages/contracts`; expression validation rules (including `JsonPathCriterionConditionRule` with code `expr.jsonpath_condition`) move to `packages/expression`, `WsdlStepRule` moves to `packages/protocol-soap`, `RpcStepRule` moves to `packages/protocol-rpc`, and `packages/document` drops its dependency on `php-arazzo/arazzo-expression`.
- **Runtime State / Cache Stores**:
  - `SourceRegistry` is not a plugin registry. It is eliminated in Phase 2 by merging document memoization (`$cache`), explicit document seeding (`$seeded`, exposed publicly via `SourcesInterface::withDocument()`), and circular-reference detection (`$resolving`) directly into `DefaultSourceResolver`.
  - State stores (`ExecutionRegistry`, `DefinitionRegistry`, `PendingCorrelationRegistry`) remain scoped to runtime/process lifecycles and are never singletons.

## Consequences

### Positive
- `ExpressionEngineInterface` is a clean, self-contained facade; external packages have zero knowledge of internal selector registries.
- Deleting `SelectorPluginRegistryInterface` removes interface-pollution and eliminates mock boilerplate across tests.
- `SelectorPluginRegistry::create()` provides a single, reliable singleton extension seam for custom selector plugins.
- Test isolation is protected via `SelectorPluginRegistry::reset()`.
- Modern, declarative registration via `#[AsSelectorPlugin]`.
- Sets a clear, unified standard for all plugin registries in the monorepo.

### Negative / Breaking Changes
- `ExpressionEngineInterface::selectorPlugins()` is removed (breaking for any code calling it directly).
- `SelectorPluginRegistryInterface` is deleted (breaking for code type-hinting it).
- `EvaluationEngine::__construct` and `RuleSet::default` no longer accept `$selectorRegistry`.
