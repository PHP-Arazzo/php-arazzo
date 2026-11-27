# Coding Standards

These standards apply to every package in the `php-arazzo` monorepo and are binding for both human contributors and AI agents.

## 1. Domain Exceptions & Static Factory Methods

1. **Always throw explicit domain exceptions, never raw SPL exceptions with ad-hoc messages.**
   - Do not write `throw new RuntimeException("...")`, `throw new InvalidArgumentException("...")`, or `throw new LogicException("...")` in package `src/` code.
   - Map every failure mode to a named domain exception class inside the owning package (extending `ArazzoException` / the package root exception, or the appropriate SPL base such as `InvalidArgumentException` / `LogicException` when callers rely on that SPL contract).
2. **Keep exception message formatting inside named static factory methods on the exception class.**
   - Throw sites MUST call a descriptive static factory rather than concatenating message strings inline:
     ```php
     // Good
     throw GotoTargetNotFoundException::unknownStep($stepId, $workflowId);
     throw UnknownWorkflowException::forId($workflowId);

     // Bad
     throw new GotoTargetNotFoundException("Action references unknown stepId '{$stepId}' in workflow '{$workflowId}'.");
     throw new RuntimeException("Workflow '{$workflowId}' not found.");
     ```
   - Exception constructors remain minimal; all human-readable prose and formatting live in one place — the exception class itself.

## 2. Rich Domain DTOs (Tell, Don't Ask)

1. **Host queries and predicates over a DTO's own data on the DTO itself.**
   - Do not scatter private `findWorkflow()`, `findStep()`, `findSource()`, or ad-hoc `array_find($document->workflows, ...)` loops across consumers, nor create stateless `*Lookup` classes solely to query a DTO's public collections.
   - Instead, ask the DTO directly:
     ```php
     // Good
     $workflow = $document->findWorkflow($workflowId);
     $source = $document->findSource($sourceName);
     $step = $workflow->findStep($stepId);
     $first = $workflow->firstStep();
     $action = $document->components->resolveReusableAction($reusable);
     $timeout = $step->flow->timeoutSeconds();
     if ($step->isActorHandoff()) { ... }

     // Bad
     $workflow = array_find($document->workflows, fn ($w) => $w->workflowId === $workflowId);
     $step = $this->lookup->findStep($workflow, $stepId);
     ```
2. **Keep DTO methods pure and bounded.**
   - DTO methods MUST stay pure (zero I/O, zero service dependencies, zero side effects) and stay within PHPMD limits (`TooManyPublicMethods` ≤ 10 non-getter/wither methods, `ExcessiveClassComplexity` WMC ≤ 50, `CouplingBetweenObjects` ≤ 13).
3. **Use named arguments when instantiating multi-parameter DTOs.**
   - For domain DTOs with multiple parameters or optional collections (such as `NormalizedOpenApiOperation` and `ResolvedOperation`), always instantiate using PHP named arguments.
   - Named arguments eliminate positional ambiguity, document parameter roles at call sites, and allow test fixtures and consumers to cleanly omit default empty collections:
     ```php
     // Good
     $operation = new NormalizedOpenApiOperation(
         path: '/pets',
         method: 'get',
         resolvedServerUrl: 'https://api.example.com',
     );

     // Bad
     $operation = new NormalizedOpenApiOperation('/pets', 'get', 'https://api.example.com', [], [], [], [], [], []);
     ```

4. **Encapsulate queries over nested payloads on Outcome and Result DTOs.**
   - Outcome and result value objects (e.g. `StepExecutionOutcome`, `StepExecutionResult`) must encapsulate queries over their internal request/response records (e.g. `$outcome->targetUrl()`, `$outcome->requestMethod()`, `$outcome->isFailed()`).
   - Consumers and taxonomy services (e.g. `ErrorClassifier`) must accept the outcome DTO directly rather than forcing caller-side decomposition of raw arrays.

## 3. Symmetric Decomposition & Quality Gates

1. **Decompose peers symmetrically.**
   - When extracting a cohesive concern out of a multi-concern class (e.g. `MarkdownRenderer` out of `Renderer`), extract its sibling peer (`MermaidRenderer`) as well so the entry point remains a thin, balanced facade.
2. **Zero-regression policy on static analysis and tests.**
   - Never introduce new PHPMD or PHPStan baseline entries (see [`docs/BASELINE-POLICY.md`](BASELINE-POLICY.md)).
   - Mark internal collaborators `@internal` so they stay out of the public API surface.
   - Maintain 100% PCOV line coverage on newly added or refactored classes before committing.
   - Follow [`docs/adr/ADR-010-monorepo-observability-and-policy-standards.md`](adr/ADR-010-monorepo-observability-and-policy-standards.md) for observability tiers and `*Policy` value objects.


## 4. Design Metrics & Single-Concern Decomposition

To prevent architectural degradation and avoid CI quality gate rejections, every class in the monorepo must strictly adhere to PHPMD design and codesize thresholds and PHPStan architectural rules:

1. **Design Metric Ceilings:**
   - **`CouplingBetweenObjects` (CBO) ≤ 13:** A class must not couple with more than 13 unique external classes (excluding primitive scalar types).
   - **`ExcessiveClassComplexity` (WMC) ≤ 50:** Weighted Methods per Class cyclomatic complexity must not exceed 50.
   - **`TooManyPublicMethods` ≤ 10:** Public methods must not exceed 10 (excluding standard getters and withers).
   - **Method Cyclomatic Complexity ≤ 10 (`arazzo.custom.cyclomaticComplexity`):** Individual methods must not exceed cyclomatic complexity 10.
   - **Identifier Length 3–30 chars (`arazzo.custom.identifierLength`):** Method and symbol identifiers must be between 3 and 30 characters.
   - **Method & Constructor Parameter Count ≤ 5 (`arazzo.custom.maxParameterCount`):** Methods and constructors must not exceed 5 parameters. Collapse multiple parameters into cohesive Value Objects or DTOs.

2. **Facade Seam Policy:**
   - Cross-package references from library code must target `*Interface` contracts, allow-listed value types, or throwables.
   - Non-facade code within library packages must NEVER reference another library package's concrete entry-point facade (`ExpressionEngine`, `Document`, `Runner`, `AsyncRunner`, `HttpProtocol`, `SourceGraph`). Always reference their contracts (`*Interface`) or internal workers (e.g. `ExpressionEvaluator`) instead. Facade-to-facade transitions are permitted only between entry-point facades.

3. **Domain Enums Namespace Convention:**
   - Domain enums must reside exclusively in the package's `Enum\` sub-namespace (e.g., `Alama\Arazzo\<Package>\Enum`), matching monorepo conventions (`Spec\Enum`, `Evaluation\Enum`, `Protocol\Http\Enum`).
   - Never place enums directly inside `Policy\` or DTO namespaces subject to `->toBeReadonly()` Pest architecture assertions, as PHP enums cannot be declared `readonly`.

4. **Single-Concern Collaborator Decomposition Guidance:**
   - When a class approaches or exceeds metric ceilings (`ExcessiveClassComplexity` > 50 or `CouplingBetweenObjects` > 13), do not silence the rule or add baseline entries.
   - Decompose the class into cohesive, single-concern collaborators marked `@internal` to keep implementation details off the public API surface.
   - Separate orchestration/coordination from evaluation, schema validation, and transport execution.
   - Group related parameter sets and domain concepts into rich Value Objects or DTOs to collapse parameter counts and coupling footprints.
   - Maintain entry points as thin facades that delegate to internal collaborators.

5. **Attributed Entry-Point Namespace Purity:**
   - Keep `Alama\Arazzo\Cli\Console\Command\` strictly for Symfony `#[AsCommand]` classes (enforced by `packages/cli/tests/ArchTest.php`).
   - Place internal command collaborators in dedicated sibling sub-namespaces (e.g., `Console\Run\`, `Console\Config\`).

6. **Concrete Collaborators Over Speculative Interfaces & Untyped Callables:**
   - Avoid introducing single-implementation `*Interface` contracts purely as test doubles, and avoid untyped `?callable` / `mixed` factory properties on constructors.
   - Prefer concrete injectable collaborators (non-`final` when subclass-spying is needed in tests, or constructor-injected dependencies); reserve `*Interface` contracts for cross-package ports and multi-implementation extension seams.

7. **Preserve Clean Interface Boundaries Under Coupling Constraints:**
   - Never use duck-typing, union types, or proxy aliasing in constructors to evade CBO ceilings. Keep domain interface contracts clean and separate responsibilities.
   - High-traffic hub classes (`StepExecutor`, `HttpStepExecutor`, `WorkflowExecutor`) operate near the CBO ceiling (≤ 13); inspect class coupling before introducing new type imports.
