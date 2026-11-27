# ADR-017: Runner Single-Step Execution Mode and Execution Result Domain Types

- **Status**: accepted
- **Issue**: #370, #371

## Context and Problem Statement

`WorkflowExecutor` previously always executed workflows starting from `firstStep()` until a terminal transition. Developers iterating on or debugging a specific failing step had to re-run the entire workflow, repeating prior side effects and external API calls. Furthermore, runner execution results (`ExecutionResult` and `StepResult`) lacked semantic clarity distinguishing workflow-level outcomes from step-level verdicts, and lacked classification of why a step ran (as the target step versus an upstream dependency).

## Decision

1. **Dedicated Single-Step Execution Verb (`RunnerInterface::runStep`) & `ExecutionPolicy::withNoDeps()`**:
   Add `public function runStep(ArazzoDocument $document, string $stepId, array $inputs = [], ?string $workflowId = null, ?ExecutionPolicy $policy = null): WorkflowExecutionResult` to `RunnerInterface` (and matching `WorkflowExecutor::executeStep()`).
   - Implicitly resolves the parent workflow if `$workflowId` is omitted (`null`). If `$stepId` exists in exactly one workflow, that workflow is selected; if it exists across multiple workflows without a disambiguating `$workflowId`, throws `AmbiguousStepException`; if it exists in no workflow, throws `UnknownStepException`.
   - The dependency inclusion mode is governed via `ExecutionPolicy` (`public bool $noDeps = false`, configured via `$policy->withNoDeps(true)`), keeping method signatures clean and consistent per ADR-010.
   - By default (`noDeps: false`), resolves transitive upstream dependencies from `DependencyGraph` (explicit `dependsOn` and implicit expressions) in topological order, breaking ties by document declaration order.
   - Stops execution immediately once the target step finishes.
   - When configured with `noDeps: true`, executes solely the target step; any missing upstream step output is checked pre-dispatch and fails fast with `step.missing_dependency_output`.
   - Halts immediately on dependency step failure with `ExecutionError::dependencyFailed($depId)`. Suppresses `goto` jumps pointing outside the dependency closure to preserve execution bounds.
   - Skips antecedent `workflow.dependsOn` workflows unless explicitly referenced by step inputs/parameters.

2. **Preflight Step Validation (R4 / S4)**:
   Extend `RunnerInterface::preflight()` with an optional `?string $stepId = null`. If the step is unknown, records a validation error listing valid step IDs; `runStep()` throws `UnknownStepException::forId($stepId, $validStepIds)`.

3. **Domain Types & Naming Clarity**:
   - Rename `ExecutionResult` to `WorkflowExecutionResult` and `StepResult` to `StepExecutionResult` across the repository to represent workflow outcomes and step verdicts with explicit domain symmetry.
   - Introduce `Alama\Arazzo\Runner\Data\Enum\StepRole` (`Standard`, `Dependency`, `Target`) on `StepExecutionResult` with query methods `isTarget()` and `isDependency()`.
   - Add `$sequentialFallbacks` to `WorkflowExecutionResult` to record reasons when concurrency flags (such as `--parallel`) fall back to sequential execution.

4. **CLI Port & Execution Flags (Issue #371)**:
   - Expose targeted execution via `arazzo run <file> --step <step-id>` (with `-s` shortcut) and isolated step execution via `--no-deps`.
   - `--no-deps` without `--step` fails fast immediately before source retrieval with an explicit validation error.
   - When `--step` is provided without `--workflow`, target workflow resolution delegates to `StepPreflightValidator::resolveTargetWorkflow()`, gracefully handling multi-workflow documents and failing before network dispatch on `UnknownStepException` or `AmbiguousStepException`.
   - Preflight validation verifies missing dependency outputs in `--no-deps` mode, failing fast with actionable advice prior to dispatch.
   - CLI output renders `(dependency)` or `(target)` tags next to step verdicts to distinguish execution roles.
   - Concurrency flag `--parallel` combined with `--step` falls back to sequential execution and displays an explicit notice.

## Considered Options

- **Single-step configuration via `ExecutionPolicy`**: Rejected in favor of an explicit `runStep()` verb on `RunnerInterface` to give targeted execution first-class ergonomics alongside `run()`.
- **Backward-compatible class aliases (`class_alias`)**: Rejected in favor of a clean, full breaking rename of `WorkflowExecutionResult` and `StepExecutionResult` to keep codebase types unified and free of legacy aliasing debt.

## Consequences

- Full runs (`Runner::run()`) and targeted runs (`Runner::runStep()`) share the same underlying pipeline components and events while providing isolated debugging iteration.
- Callers must use the canonical domain names `WorkflowExecutionResult` and `StepExecutionResult`.
