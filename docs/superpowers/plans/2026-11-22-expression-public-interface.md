# Expression Package Public Interface Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Refactor `alama/arazzo-expression` public-interface discipline:
1. Promote `ExpressionEngineInterface` as the sole external seam for expression parsing, reference projection, and selector execution.
2. Add `evaluateRawSelector()`, `xpath()`, and `supportedSelectorVersions()` to `ExpressionEngineInterface` so consumers never need direct access to selector registries or plugins.
3. Remove `selectorPlugins()` from `ExpressionEngineInterface` and `ExpressionEngine`.
4. Delete `SelectorPluginRegistryInterface` entirely — `SelectorPluginRegistry` becomes a concrete singleton with `public static function create(): self` and `reset(): void`.
5. Introduce `#[AsSelectorPlugin]` attribute in `packages/contracts` for declarative plugin metadata.
6. Keep `SelectorPluginRegistry` `@internal` inside `packages/expression` (an internal strategy implementation for the expression package); external extension happens via `SelectorPluginInterface` + `#[AsSelectorPlugin]` + Composer `extra.arazzo.plugins` directories.
7. Purge all `$selectorRegistry` parameters from `packages/document` (`RuleSet`) and `packages/evaluation` (`EvaluationEngine`, `CriteriaEvaluator`, `BuiltInCriterionEvaluator`, `PayloadReplacer`).
8. Promote `InterpolationOptions` to public and enforce boundary rules via `ArchTest`.

---

### Phase Roadmap

- **Phase 1 (This Plan / Branch `expression-public-interface-design`)**:
  - `ExpressionEngineInterface` expansion (`evaluateRawSelector`, `xpath`, `supportedSelectorVersions`) & removal of `selectorPlugins()`.
  - `ExpressionEngine::create()` static factory.
  - `#[AsSelectorPlugin]` attribute in `packages/contracts`.
  - Concrete singleton `SelectorPluginRegistry::create()` and `reset()`.
  - Complete deletion of `SelectorPluginRegistryInterface`.
  - Purge `$selectorRegistry` from `document` and `evaluation`.
  - Boundary architecture tests in `packages/expression/tests/ArchTest.php`.
- **Phase 2 (Cross-Monorepo Registry Alignment — Follow-up Branch/PR)**:
  - Record ADR for Unified Registry & Plugin Architecture.
  - Delete `SourceNormalizerRegistryInterface`, turn `SourceNormalizerRegistry` into concrete singleton with `create()`.
  - Turn `OperationExecutorRegistry` into concrete singleton with `create()`.
  - Turn `ExpressionEvaluatorRegistry` and `CriterionEvaluatorRegistry` into singletons, encapsulated in `EvaluationEngine`.
  - Eliminate `SourceRegistry` wrapper by merging document caching and circular reference protection directly into `DefaultSourceResolver`.
  - Framework auto-discovery in `laravel` and `cli` via PHP 8 attributes.

---

### Global Constraints (Phase 1)

- PHP `^8.4`, composer package `php-arazzo/arazzo-expression` (`@dev`).
- Public API allow-list for `packages/expression`:
  - Seam: `ExpressionEngineInterface`, `ExpressionEngine`.
  - Extension Point: `SelectorPluginInterface`.
  - Value Objects: `ExpressionReference`, `EmbeddedSegment`, `InterpolationOptions`.
  - Exceptions: `ExpressionSyntaxException`, `SelectorEvaluationException`.
- All other classes (`SelectorPluginRegistry`, `Parser`, `Lexer`, `Interpolator`, `ExpressionEvaluator`, `SelectorEvaluator`, `JsonPathEvaluator`, `ExpressionDependencyProvider`, `Plugins\*`, `Ast\*`, `JsonPath\*`, `Xpath\*`, `Internal\*`) stay `@internal`.
- `SelectorPluginRegistryInterface` is deleted from git.
- `packages/expression` has zero dependencies on `Alama\Arazzo\Document`, `Alama\Arazzo\Runner`, `Alama\Arazzo\Sources`, or `Alama\Arazzo\Evaluation`.

---

### Task 1: Add `#[AsSelectorPlugin]` attribute in `packages/contracts`

**Files:**
- Create: `packages/contracts/src/Attributes/AsSelectorPlugin.php`
- Test: `packages/contracts/tests/Attributes/AsSelectorPluginTest.php`

- [ ] **Step 1: Write test for `#[AsSelectorPlugin]`**

Create `packages/contracts/tests/Attributes/AsSelectorPluginTest.php`:
```php
<?php

declare(strict_types=1);

namespace Alama\Arazzo\Contracts\Tests\Attributes;

use Alama\Arazzo\Contracts\Attributes\AsSelectorPlugin;
use Alama\Arazzo\Contracts\Spec\Enum\ExpressionType;

it('instantiates AsSelectorPlugin attribute with type and priority', function (): void {
    $attr = new AsSelectorPlugin(ExpressionType::JsonPath, priority: 10);

    expect($attr->type)->toBe(ExpressionType::JsonPath)
        ->and($attr->priority)->toBe(10);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest packages/contracts/tests/Attributes/AsSelectorPluginTest.php`

- [ ] **Step 3: Implement `AsSelectorPlugin`**

Create `packages/contracts/src/Attributes/AsSelectorPlugin.php`:
```php
<?php

declare(strict_types=1);

namespace Alama\Arazzo\Contracts\Attributes;

use Alama\Arazzo\Contracts\Spec\Enum\ExpressionType;
use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class AsSelectorPlugin
{
    public function __construct(
        public ExpressionType $type,
        public int $priority = 0,
    ) {}
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest packages/contracts/tests/Attributes/AsSelectorPluginTest.php`

---

### Task 2: Concrete Singleton `SelectorPluginRegistry` & Delete `SelectorPluginRegistryInterface`

**Files:**
- Modify: `packages/expression/src/Registries/SelectorPluginRegistry.php`
- Modify: `packages/expression/src/Plugins/JsonPathSelectorPlugin.php`
- Modify: `packages/expression/src/Plugins/JsonPointerSelectorPlugin.php`
- Modify: `packages/expression/src/Plugins/XpathSelectorPlugin.php`
- Modify: `packages/expression/src/SelectorEvaluator.php`
- Modify: `packages/expression/src/ExpressionDependencyProvider.php`
- Delete: `packages/expression/src/Interfaces/SelectorPluginRegistryInterface.php`
- Test: `packages/expression/tests/Registries/SelectorPluginRegistryTest.php`

- [ ] **Step 1: Write test for singleton `SelectorPluginRegistry::create()` and `reset()`**

Create `packages/expression/tests/Registries/SelectorPluginRegistryTest.php`:
```php
<?php

declare(strict_types=1);

namespace Alama\Arazzo\Tests\Expression\Registries;

use Alama\Arazzo\Contracts\Spec\Enum\ExpressionType;
use Alama\Arazzo\Expression\Interfaces\SelectorPluginInterface;
use Alama\Arazzo\Expression\Registries\SelectorPluginRegistry;

afterEach(function (): void {
    SelectorPluginRegistry::reset();
});

it('returns the same singleton instance from create()', function (): void {
    $instance1 = SelectorPluginRegistry::create();
    $instance2 = SelectorPluginRegistry::create();

    expect($instance1)->toBe($instance2);
});

it('pre-populates default plugins on creation', function (): void {
    $registry = SelectorPluginRegistry::create();

    expect($registry->has(ExpressionType::JsonPath))->toBeTrue()
        ->and($registry->has(ExpressionType::JsonPointer))->toBeTrue()
        ->and($registry->has(ExpressionType::XPath))->toBeTrue();
});

it('resets singleton state via reset()', function (): void {
    $instance1 = SelectorPluginRegistry::create();
    SelectorPluginRegistry::reset();
    $instance2 = SelectorPluginRegistry::create();

    expect($instance1)->not->toBe($instance2);
});

it('registers custom plugins with priority and attribute reflection', function (): void {
    $registry = SelectorPluginRegistry::create();
    $customPlugin = new class() implements SelectorPluginInterface {
        public function supports(ExpressionType $type): bool { return $type === ExpressionType::JsonPath; }
        public function evaluate(mixed $rootValue, string $expression, ?string $version, ?array $namespaces = null): mixed { return 'custom'; }
        public function supportedVersions(): array { return ['custom-1']; }
    };

    $registry->register(ExpressionType::JsonPath, $customPlugin, priority: 100);

    expect($registry->get(ExpressionType::JsonPath))->toBe($customPlugin);
});
```

- [ ] **Step 2: Implement singleton `SelectorPluginRegistry`**

Update `packages/expression/src/Registries/SelectorPluginRegistry.php`:
- Remove `implements SelectorPluginRegistryInterface`.
- Make `private function __construct()`.
- Add `private static ?self $instance = null;`.
- Add `public static function create(): self` which initializes `$instance` with default plugins (`JsonPathSelectorPlugin`, `JsonPointerSelectorPlugin`, `XpathSelectorPlugin` with `Xpath31Evaluator`) if `null`.
- Add `public static function reset(): void` which sets `$instance = null`.
- Add `register(ExpressionType|SelectorPluginInterface $typeOrPlugin, ?SelectorPluginInterface $plugin = null, int $priority = 0): void` supporting both explicit registration and attribute-driven registration via `#[AsSelectorPlugin]`.

- [ ] **Step 3: Add `#[AsSelectorPlugin]` to default plugins**
- Update `JsonPathSelectorPlugin` with `#[AsSelectorPlugin(ExpressionType::JsonPath, 0)]`.
- Update `JsonPointerSelectorPlugin` with `#[AsSelectorPlugin(ExpressionType::JsonPointer, 0)]`.
- Update `XpathSelectorPlugin` with `#[AsSelectorPlugin(ExpressionType::XPath, 0)]`.

- [ ] **Step 4: Update `SelectorEvaluator` and `ExpressionDependencyProvider`**
- In `SelectorEvaluator`: change constructor argument from `SelectorPluginRegistryInterface $plugins` to `SelectorPluginRegistry $plugins`.
- In `ExpressionDependencyProvider`: remove `$selectorPlugins` constructor parameter; `getSelectorPluginRegistry()` returns `SelectorPluginRegistry::create()`.

- [ ] **Step 5: Delete `SelectorPluginRegistryInterface.php`**
- Delete `packages/expression/src/Interfaces/SelectorPluginRegistryInterface.php`.

- [ ] **Step 6: Run tests to verify `SelectorPluginRegistry` passes**
Run: `./vendor/bin/pest packages/expression/tests/Registries/SelectorPluginRegistryTest.php`

---

### Task 3: Expand `ExpressionEngineInterface` and Implement on `ExpressionEngine`

**Files:**
- Modify: `packages/expression/src/Interfaces/ExpressionEngineInterface.php`
- Modify: `packages/expression/src/ExpressionEngine.php`
- Modify: `packages/expression/src/Data/InterpolationOptions.php`
- Test: `packages/expression/tests/ExpressionEngineTest.php`

- [ ] **Step 1: Write test for new methods and `ExpressionEngine::create()`**

Update `packages/expression/tests/ExpressionEngineTest.php`:
- Test `ExpressionEngine::create()` returns `ExpressionEngineInterface`.
- Test `evaluateRawSelector(ExpressionType::JsonPath, '$.name', ['name' => 'Arazzo']) === 'Arazzo'`.
- Test `xpath('//item/text()', '<items><item>test</item></items>') === 'test'`.
- Test `supportedSelectorVersions(ExpressionType::JsonPath)` returns `['rfc9535']`.
- Test `InterpolationOptions` constructible without `@internal`.

- [ ] **Step 2: Update `ExpressionEngineInterface`**
- Add:
  ```php
  public function evaluateRawSelector(
      ExpressionType $type,
      string $expression,
      mixed $data,
      ?string $version = null,
      ?array $namespaces = null,
  ): mixed;

  public function xpath(
      string $expression,
      mixed $data,
      ?string $version = null,
      ?array $namespaces = null,
  ): mixed;

  /**
   * @return list<string>
   */
  public function supportedSelectorVersions(ExpressionType $type): array;
  ```
- Remove:
  ```php
  public function selectorPlugins(): SelectorPluginRegistryInterface;
  ```

- [ ] **Step 3: Update `ExpressionEngine`**
- Add `public static function create(): self { return new self(); }`.
- Remove `$selectorPlugins` parameter from `__construct()`.
- Remove `selectorPlugins()` method.
- Implement `evaluateRawSelector()`: looks up plugin in `SelectorPluginRegistry::create()->get($type)`; throws `SelectorEvaluationException` if null; delegates to `$plugin->evaluate($data, $expression, $version, $namespaces)`.
- Implement `xpath()`: delegates to `evaluateRawSelector(ExpressionType::XPath, $expression, $data, $version, $namespaces)`.
- Implement `supportedSelectorVersions()`: delegates to `SelectorPluginRegistry::create()->supportedVersions($type)`.
- Promote `packages/expression/src/Data/InterpolationOptions.php` by removing `@internal`.

- [ ] **Step 4: Run tests to verify ExpressionEngine passes**
Run: `./vendor/bin/pest packages/expression/tests/ExpressionEngineTest.php`

---

### Task 4: Purge `$selectorRegistry` from `document` and `evaluation`

**Files:**
- Modify: `packages/document/src/Validator/RuleSet.php`
- Modify: `packages/document/tests/Validator/Rules/ActionOutputTargetExclusivityRuleTest.php`
- Modify: `packages/document/tests/Validator/Rules/ExpressionJsonPointerSyntaxRuleTest.php`
- Modify: `packages/document/tests/Validator/Rules/ExpressionUnresolvedStepRefRuleTest.php`
- Modify: `packages/document/tests/Validator/Rules/MessageRefRequiresReceiveRuleTest.php`
- Modify: `packages/evaluation/src/EvaluationEngine.php`
- Modify: `packages/evaluation/src/CriteriaEvaluator.php`
- Modify: `packages/evaluation/src/BuiltInCriterionEvaluator.php`
- Modify: `packages/evaluation/src/PayloadReplacer.php`

- [ ] **Step 1: Refactor `packages/document`**
- In `RuleSet::default(ExpressionEngineInterface $engine, array $disabled = [], bool $strict = true)`:
  - Remove `$selectorRegistry` parameter and its import.
  - Pass `$engine->supportedSelectorVersions(ExpressionType::JsonPath)` directly to `SelectorTypeSupportedRule`.
- In test files (`ActionOutputTargetExclusivityRuleTest`, etc.):
  - Remove all anonymous dummy implementations of `selectorPlugins()`.

- [ ] **Step 2: Refactor `packages/evaluation`**
- In `BuiltInCriterionEvaluator`:
  - Replace `SelectorPluginRegistryInterface $selectorRegistry` with `ExpressionEngineInterface $expression`.
  - `evaluateJsonPath`: call `$this->expression->jsonPath($criterion->condition, $root); return !empty($result);`.
  - `evaluateXPath`: call `$this->expression->xpath($criterion->condition, $root, $version); return ConditionEvaluator::truthy($result);`.
- In `PayloadReplacer`:
  - In `apply()` and `applySelectorTarget()`: remove `$selectorRegistry` argument.
  - In `applySelectorTarget()`: replace `$selectorRegistry->get(ExpressionType::XPath)` with `$expression->xpath($replacement->target, $body, 'xpath-10')`.
- In `CriteriaEvaluator`:
  - Remove `?SelectorPluginRegistryInterface $selectorRegistry = null` constructor parameter.
  - Pass `$this->expression` into `BuiltInCriterionEvaluator`.
- In `EvaluationEngine`:
  - Remove `?SelectorPluginRegistryInterface $selectorRegistry = null` constructor parameter and `$this->selectorRegistry` property.

- [ ] **Step 3: Run document and evaluation test suites**
Run: `./vendor/bin/pest packages/document/tests packages/evaluation/tests`

---

### Task 5: ArchTest Updates & Monorepo Verification

**Files:**
- Modify: `packages/expression/tests/ArchTest.php`

- [ ] **Step 1: Update `packages/expression/tests/ArchTest.php`**
- Allow-list:
  - Add `SelectorPluginRegistry` and `SelectorPluginInterface` as public APIs.
  - Remove `SelectorPluginRegistryInterface`.
- Internals boundary:
  - Assert `SelectorPluginRegistryInterface` is not used by any package.
  - Assert `ExpressionDependencyProvider` stays inside `packages/expression`.
  - Assert zero dependencies on `Document`, `Runner`, `Sources`, `Evaluation`.

- [ ] **Step 2: Run all test suites across the monorepo**
Run: `./vendor/bin/pest`

- [ ] **Step 3: Check git status and commit**
```bash
git add .
git commit -m "feat(expression): finalize public interface, singletonize SelectorPluginRegistry, and decouple external packages"
```
