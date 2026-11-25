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

## 3. Symmetric Decomposition & Quality Gates

1. **Decompose peers symmetrically.**
   - When extracting a cohesive concern out of a multi-concern class (e.g. `MarkdownRenderer` out of `Renderer`), extract its sibling peer (`MermaidRenderer`) as well so the entry point remains a thin, balanced facade.
2. **Zero-regression policy on static analysis and tests.**
   - Never introduce new PHPMD or PHPStan baseline entries (see [`docs/BASELINE-POLICY.md`](BASELINE-POLICY.md)).
   - Mark internal collaborators `@internal` so they stay out of the public API surface.
   - Maintain 100% PCOV line coverage on newly added or refactored classes before committing.
   - Follow [`docs/adr/ADR-010-monorepo-observability-and-policy-standards.md`](adr/ADR-010-monorepo-observability-and-policy-standards.md) for observability tiers and `*Policy` value objects.
