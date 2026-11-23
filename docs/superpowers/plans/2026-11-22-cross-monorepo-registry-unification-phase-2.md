# Cross-Monorepo Registry Unification & Universal Plugin Attributes (Phase 2 Implementation Plan)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Execute Phase 2 of the unified registry architecture across the monorepo:
1. Introduce remaining PHP 8 attributes in `packages/contracts`: `#[AsSourceNormalizer]`, `#[AsOperationExecutor]`, `#[AsCriterionPlugin]`, `#[AsExpressionPlugin]`.
2. Convert `SourceNormalizerRegistry` in `packages/sources` into a concrete singleton with `create(): self` and `reset(): void`, delete `SourceNormalizerRegistryInterface`, and decorate all source normalizers.
3. Convert `OperationExecutorRegistry` in `packages/runner` into a concrete singleton with `create(): self` and `reset(): void`, decorate all step executors, and decouple execution graph assemblers.
4. Convert `ExpressionEvaluatorRegistry` and `CriterionEvaluatorRegistry` in `packages/evaluation` into concrete singletons with `create(): self` and `reset(): void`, decorate default plugins, and encapsulate them in `EvaluationEngine`.
5. Eliminate the `SourceRegistry` decorator by merging document memoization, circular-reference detection, and in-memory document seeding directly into `DefaultSourceResolver`.
6. Integrate container auto-discovery in `alama/laravel-arazzo` and `cli`.

**Spec Reference:** `docs/superpowers/specs/2026-11-22-expression-package-interface-design.md`
**ADR Reference:** `docs/adr/ADR-007-expression-package-public-interface-and-plugin-registries.md`

---

### Task 1: Add Universal Plugin Attributes in `packages/contracts`

**Files:**
- Create: `packages/contracts/src/Attributes/AsSourceNormalizer.php`
- Create: `packages/contracts/src/Attributes/AsOperationExecutor.php`
- Create: `packages/contracts/src/Attributes/AsCriterionPlugin.php`
- Create: `packages/contracts/src/Attributes/AsExpressionPlugin.php`
- Test: `packages/contracts/tests/Attributes/UniversalPluginAttributesTest.php`

- [ ] **Step 1: Write test for all plugin attributes**

Create `packages/contracts/tests/Attributes/UniversalPluginAttributesTest.php`:
```php
<?php

declare(strict_types=1);

namespace Alama\Arazzo\Contracts\Tests\Attributes;

use Alama\Arazzo\Contracts\Attributes\AsCriterionPlugin;
use Alama\Arazzo\Contracts\Attributes\AsExpressionPlugin;
use Alama\Arazzo\Contracts\Attributes\AsOperationExecutor;
use Alama\Arazzo\Contracts\Attributes\AsSourceNormalizer;
use Alama\Arazzo\Contracts\Spec\Enum\SourceType;

it('instantiates AsSourceNormalizer attribute', function (): void {
    $attr = new AsSourceNormalizer(SourceType::OpenApi, priority: 10);
    expect($attr->type)->toBe(SourceType::OpenApi)->and($attr->priority)->toBe(10);
});

it('instantiates AsOperationExecutor attribute', function (): void {
    $attr = new AsOperationExecutor(priority: 5);
    expect($attr->priority)->toBe(5);
});

it('instantiates AsCriterionPlugin attribute', function (): void {
    $attr = new AsCriterionPlugin('jsonpath', priority: 0);
    expect($attr->type)->toBe('jsonpath')->and($attr->priority)->toBe(0);
});

it('instantiates AsExpressionPlugin attribute', function (): void {
    $attr = new AsExpressionPlugin(priority: 0);
    expect($attr->priority)->toBe(0);
});
```

- [ ] **Step 2: Run test to verify it fails**
Run: `./vendor/bin/pest packages/contracts/tests/Attributes/UniversalPluginAttributesTest.php`

- [ ] **Step 3: Implement attribute classes in `packages/contracts/src/Attributes/`**
- Create `AsSourceNormalizer.php` with `#[Attribute(Attribute::TARGET_CLASS)]`.
- Create `AsOperationExecutor.php` with `#[Attribute(Attribute::TARGET_CLASS)]`.
- Create `AsCriterionPlugin.php` with `#[Attribute(Attribute::TARGET_CLASS)]`.
- Create `AsExpressionPlugin.php` with `#[Attribute(Attribute::TARGET_CLASS)]`.

- [ ] **Step 4: Run test to verify it passes**
Run: `./vendor/bin/pest packages/contracts/tests/Attributes/UniversalPluginAttributesTest.php`

---

### Task 2: Singletonize `SourceNormalizerRegistry` & Delete `SourceNormalizerRegistryInterface`

**Files:**
- Modify: `packages/sources/src/Resolver/SourceNormalizerRegistry.php`
- Delete: `packages/contracts/src/Interfaces/SourceNormalizerRegistryInterface.php`
- Modify: `packages/protocol-http/src/Registrar/ProtocolHttpRegistrar.php`
- Modify: `packages/protocol-http/src/Normalizer/OpenApiSourceNormalizer.php`
- Modify: `packages/protocol-rpc/src/Registrar/RpcRegistrar.php`
- Modify: `packages/protocol-rpc/src/Normalizer/ProtoSourceNormalizer.php`
- Modify: `packages/protocol-soap/src/Registrar/SoapRegistrar.php`
- Modify: `packages/protocol-soap/src/Normalizer/WsdlSourceNormalizer.php`
- Modify: `packages/sources/src/SourceLoader.php`
- Modify: `packages/sources/src/SourceGraph.php`
- Test: `packages/sources/tests/Resolver/SourceNormalizerRegistryTest.php`

- [ ] **Step 1: Write test for singleton `SourceNormalizerRegistry::create()` and `reset()`**
- Test that `SourceNormalizerRegistry::create()` returns the same instance.
- Test that `SourceNormalizerRegistry::reset()` clears the instance.
- Test that attribute-driven registration via `#[AsSourceNormalizer]` automatically reads type and priority.

- [ ] **Step 2: Implement singleton `SourceNormalizerRegistry`**
- Make constructor `private`.
- Add `create(): self` and `reset(): void`.
- Update `register(SourceNormalizerInterface $normalizer)` to read `#[AsSourceNormalizer]` for priority and supported type.
- Remove `implements SourceNormalizerRegistryInterface`.
- Delete `packages/contracts/src/Interfaces/SourceNormalizerRegistryInterface.php`.

- [ ] **Step 3: Decorate normalizers with `#[AsSourceNormalizer]`**
- Decorate `OpenApiSourceNormalizer` with `#[AsSourceNormalizer(SourceType::OpenApi)]`.
- Decorate `ProtoSourceNormalizer` with `#[AsSourceNormalizer(SourceType::Protobuf)]`.
- Decorate `WsdlSourceNormalizer` with `#[AsSourceNormalizer(SourceType::Wsdl)]`.

- [ ] **Step 4: Update protocol registrars and `SourceLoader` / `SourceGraph`**
- Update `ProtocolHttpRegistrar`, `RpcRegistrar`, and `SoapRegistrar`: replace `registerInto(?SourceNormalizerRegistryInterface)` with `register(): void` which calls `SourceNormalizerRegistry::create()->register($this->normalizer())`.
- Update `SourceLoader` and `SourceGraph` to drop `$normalizers` constructor parameters and use `SourceNormalizerRegistry::create()` internally.

- [ ] **Step 5: Run sources and protocol tests to verify passing**
Run: `./vendor/bin/pest packages/sources/tests packages/protocol-http/tests packages/protocol-rpc/tests packages/protocol-soap/tests`

---

### Task 3: Singletonize `OperationExecutorRegistry` in `runner`

**Files:**
- Modify: `packages/runner/src/OperationExecutorRegistry.php`
- Modify: `packages/protocol-http/src/Execution/HttpStepExecutor.php`
- Modify: `packages/protocol-http/src/Execution/AsyncApiStepExecutor.php`
- Modify: `packages/protocol-rpc/src/Execution/RpcOperationExecutor.php`
- Modify: `packages/protocol-soap/src/Execution/SoapOperationExecutor.php`
- Modify: `packages/runner/src/Sync/SubWorkflowStepExecutor.php`
- Modify: `packages/runner/src/Sync/ExecutionGraphFactory.php`
- Modify: `packages/runner/src/Sync/StepExecutor.php`
- Modify: `packages/runner/src/Async/StepExecutionWorker.php`
- Modify: `packages/runner/src/Async/AsyncExecutionGraphAssembler.php`
- Modify: `packages/cli/src/Console/Cli/CliRunner.php`
- Test: `packages/runner/tests/OperationExecutorRegistryTest.php`

- [ ] **Step 1: Write test for singleton `OperationExecutorRegistry::create()` and `reset()`**
- Test that `OperationExecutorRegistry::create()` returns the same instance.
- Test that `OperationExecutorRegistry::reset()` clears the instance.
- Test that attribute-driven registration via `#[AsOperationExecutor]` reads priority.

- [ ] **Step 2: Implement singleton `OperationExecutorRegistry`**
- Add `private function __construct()`.
- Add `public static function create(): self` and `public static function reset(): void`.
- Update `register(OperationExecutorPluginInterface $plugin)` to read `#[AsOperationExecutor]`.

- [ ] **Step 3: Decorate executors with `#[AsOperationExecutor]`**
- Decorate `HttpStepExecutor`, `AsyncApiStepExecutor`, `RpcOperationExecutor`, `SoapOperationExecutor`, `SubWorkflowStepExecutor`.

- [ ] **Step 4: Decouple execution graph assemblers**
- Update `ExecutionGraphFactory`, `StepExecutor`, `StepExecutionWorker`, `CliRunner` to default to `OperationExecutorRegistry::create()`.
- Update protocol registrars (`ProtocolHttpRegistrar::register()`, etc.) to register their executors directly into `OperationExecutorRegistry::create()`.

- [ ] **Step 5: Run runner and cli test suites**
Run: `./vendor/bin/pest packages/runner/tests packages/cli/tests`

---

### Task 4: Singletonize `ExpressionEvaluatorRegistry` & `CriterionEvaluatorRegistry` in `evaluation`

**Files:**
- Modify: `packages/evaluation/src/Registries/ExpressionEvaluatorRegistry.php`
- Modify: `packages/evaluation/src/Registries/CriterionEvaluatorRegistry.php`
- Modify: `packages/evaluation/src/Plugins/JsonPathExpressionPlugin.php`
- Modify: `packages/evaluation/src/Plugins/JsonPathCriterionPlugin.php`
- Modify: `packages/evaluation/src/EvaluationEngine.php`
- Modify: `packages/evaluation/src/CriteriaEvaluator.php`
- Test: `packages/evaluation/tests/Registries/EvaluationRegistriesTest.php`

- [ ] **Step 1: Write test for singleton evaluation registries**
- Test `ExpressionEvaluatorRegistry::create()` and `reset()`.
- Test `CriterionEvaluatorRegistry::create()` and `reset()`.
- Test that default JsonPath plugins are registered on creation.

- [ ] **Step 2: Implement singleton factories on evaluation registries**
- Add `private __construct(?ExpressionEngineInterface $expression = null)`.
- Add `public static function create(?ExpressionEngineInterface $expression = null): self` and `reset(): void`.
- Update `register()` to read `#[AsExpressionPlugin]` and `#[AsCriterionPlugin]`.

- [ ] **Step 3: Decorate default plugins**
- Decorate `JsonPathExpressionPlugin` with `#[AsExpressionPlugin(0)]`.
- Decorate `JsonPathCriterionPlugin` with `#[AsCriterionPlugin('jsonpath', 0)]`.

- [ ] **Step 4: Encapsulate within `EvaluationEngine`**
- Remove `$expressionRegistry` and `$criterionRegistry` from `EvaluationEngine::__construct()`.
- Remove `$criterionRegistry` from `CriteriaEvaluator::__construct()`.
- `EvaluationEngine` uses `ExpressionEvaluatorRegistry::create($this->expression)` and `CriterionEvaluatorRegistry::create($this->expression)` internally.

- [ ] **Step 5: Run evaluation test suite**
Run: `./vendor/bin/pest packages/evaluation/tests`

---

### Task 5: Eliminate `SourceRegistry` Decorator into `DefaultSourceResolver`

**Files:**
- Modify: `packages/sources/src/Resolver/DefaultSourceResolver.php`
- Delete: `packages/sources/src/Resolver/SourceRegistry.php`
- Modify: `packages/sources/src/SourceLoader.php`
- Modify: `packages/sources/src/SourceGraph.php`
- Modify: `packages/cli/src/Console/Command/RunCommand.php`
- Modify: `packages/core/tests/Conformance/ConformanceHarness.php`
- Modify: `packages/core/tests/Conformance/OaiCorpusRunner.php`
- Modify: `packages/core/tests/Validator/InputsPreValidationTest.php`
- Modify: `packages/runner/tests/Sync/WorkflowExecutorTest.php`
- Test: `packages/sources/tests/Resolver/DefaultSourceResolverTest.php`

- [ ] **Step 1: Write tests for memoization and circular-reference detection directly on `DefaultSourceResolver`**
- Test that resolving a source twice reuses the memoized `SourceDocument`.
- Test that circular references throw `UnresolvableReferenceException`.
- Test `DefaultSourceResolver::inMemory(array $documents): self`.

- [ ] **Step 2: Implement memoization directly in `DefaultSourceResolver`**
- Add `private array $documents = [];` and `private array $resolving = [];` to `DefaultSourceResolver`.
- In `resolve(SourceDescription $source, string $basePath): SourceDocument`: check `$this->documents`, check `$this->resolving`, fetch/decode, cache in `$this->documents`, and clear `$this->resolving`.
- Add `public function setDocument(SourceDocument $document): void` and `public static function inMemory(array $documents = []): self`.

- [ ] **Step 3: Delete `SourceRegistry.php`**
- Delete `packages/sources/src/Resolver/SourceRegistry.php`.
- Update all callers and test harnesses to use `DefaultSourceResolver` or `DefaultSourceResolver::inMemory()`.

- [ ] **Step 4: Run all test suites across the monorepo**
Run: `./vendor/bin/pest`

---

### Task 6: Framework Auto-Discovery in `laravel` and `cli`

**Files:**
- Modify: `packages/cli/src/Console/Cli/CliRunner.php`
- Modify: `packages/laravel/src/ArazzoServiceProvider.php` (if present) or `packages/core/`

- [ ] **Step 1: Wire container auto-discovery for `#[As...]` attributes**
- Register core singletons (`SelectorPluginRegistry`, `SourceNormalizerRegistry`, `OperationExecutorRegistry`, `ExpressionEvaluatorRegistry`, `CriterionEvaluatorRegistry`) as container singletons.
- In `CliRunner` and Laravel service provider, register package defaults into the singletons.

- [ ] **Step 2: Run complete monorepo test suite and architecture tests**
Run: `./vendor/bin/pest`
Run: `git status`
