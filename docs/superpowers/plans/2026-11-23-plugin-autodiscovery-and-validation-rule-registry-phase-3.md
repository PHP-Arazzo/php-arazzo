# Phase 3: Composer Manifest Plugin Auto-Discovery & `ValidationRuleRegistry`

## Goal
Turn the Phase 1–2 PHP 8 plugin attributes (`#[AsSelectorPlugin]`, `#[AsSourceNormalizer]`, `#[AsOperationExecutor]`, `#[AsCriterionPlugin]`, `#[AsExpressionPlugin]`, and new `#[AsValidationRule]`) into true zero-scan auto-discovery via `composer.json` `extra.arazzo.plugins`, retire the pre-attribute `Protocol*Registrar` classes, and fully decouple `php-arazzo/arazzo-document` from `php-arazzo/arazzo-expression`.

## Implemented Changes
1. **`Alama\Arazzo\Contracts\Discovery\PluginDiscovery` & `#[AsValidationRule]`**:
   - Reads `extra.arazzo.plugins` from all installed Composer packages via `Composer\InstalledVersions` + root `composer.json` (memoized, with `overrideClasses()` / `reset()` for tests).
   - `classesWith(string $attributeClass)` serves both stateless registries and DI-bound framework bridges (`#[AsOperationExecutor]`).
   - `instantiate(string $attributeClass, array $services = [])` instantiates via zero-arg `static create()` or type-matched service/default constructor injection, throwing loudly on unsatisfied required parameters.
2. **All 5 Stateless Registries Auto-Discover**:
   - `SelectorPluginRegistry`, `SourceNormalizerRegistry`, `ValidationRuleRegistry`, `ExpressionEvaluatorRegistry`, and `CriterionEvaluatorRegistry` all auto-discover plugins from `extra.arazzo.plugins` inside `create()`.
   - Deleted obsolete `@internal` `ProtocolHttpRegistrar`, `SoapRegistrar`, `RpcRegistrar`, `ProtocolHttpRegistrarTest`, and removed `ProtocolHttpRegistrar` from PHPStan/PHPMD baselines.
3. **Validation Rule SPI & `packages/document` Decoupling**:
   - Promoted `ValidationRuleInterface` and `SymbolTable`, `WorkflowSymbols`, `StepSymbols`, `ErrorCollector`, `Error`, `Warning`, `Severity`, `ValidationResult` to `packages/contracts`.
   - Added `Alama\Arazzo\Document\Registries\ValidationRuleRegistry` (`create()` / `reset()` / `register()` / `get()` / `has()` / `all()`).
   - Moved expression rules + `ExpressionWalker`/`ExpressionSite` to `packages/expression` and extracted `OfficialSchemaRule`'s JSONPath check into `JsonPathCriterionConditionRule` (`expr.jsonpath_condition`).
   - Moved `WsdlStepRule` to `packages/protocol-soap` and `RpcStepRule` to `packages/protocol-rpc`.
   - Removed `php-arazzo/arazzo-expression` from `packages/document/composer.json` and synced `composer.lock`.
   - Updated `Document` / `DocumentInterface`: dropped `ExpressionEngineInterface` constructor param and `withExpressionEngine()`, added immutable `withRule(ValidationRuleInterface $rule): static`.
4. **Standalone `document` Documentation**:
   - Documented clearly in `packages/document/README.md`, `packages/core/README.md`, and root `README.md` that standalone `arazzo-document` runs structural/referential rules only, while installing `arazzo-expression` (or default `arazzo-core`) auto-activates expression validation rules.
