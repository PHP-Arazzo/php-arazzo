# ADR-011: Static Expression Walking for Action Criteria & Parameters with Step/Workflow Context Isolation

## Status

Accepted

## Date

2026-11-26

## Issue

#344 — `fix(document): walk action criteria and parameters for unresolved expressions`

## Context

Static reference validation rules (`ExpressionUnresolvedStepRefRule`, `ExpressionUnresolvedWorkflowRefRule`, `ExpressionUnresolvedInputRefRule`, `ExpressionContextMisuseRule`) consume `ExpressionSite` instances yielded by `ExpressionWalker::walk()`.

Previously, `ExpressionWalker` only visited:
- Workflow parameters and outputs
- Step parameters, request bodies, successCriteria, and outputs

It skipped action criteria conditions/contexts and action parameters across both step-level flow actions (`onSuccess`, `onFailure`, `onTimeout`, `onCancel`) and workflow-level actions (`successActions`, `failureActions`). Consequently, invalid step, input, or workflow references within actions passed static validation (`valid=true`) and failed only at runtime during evaluation.

Furthermore, runtime HTTP references (`$response`, `$request`, `$statusCode`, `$url`, `$method`) are legally accessible during step-level action execution (where the completed HTTP transaction is in scope), but are forbidden at the workflow level where no single HTTP transaction exists.

## Decision

1. **Comprehensive Action Flow Traversal**:
   Extend `ExpressionWalker` to traverse:
   - Step actions across all four `StepFlow` action lists: `onSuccess`, `onFailure`, `onTimeout`, and `onCancel`.
   - Workflow-level actions: `Workflow::$successActions` and `Workflow::$failureActions`.

2. **Action Parameter Support Across Action Types**:
   - `GotoAction` (`SuccessGotoAction`, `FailureGotoAction`): Walk each parameter's `value` expression in `parameters: list<Parameter|Reusable>`, skipping `Reusable` instances. Pointers format: `/workflows/{wi}/steps/{si}/{actionList}/{ai}/parameters/{pi}/value` (or workflow-level `/workflows/{wi}/{actionList}/{ai}/parameters/{pi}/value`).
   - `SubWorkflowAction` (`SubWorkflowSuccessAction`, `SubWorkflowFailureAction`): Walk each parameter entry in `parameters: array<string, mixed>`, resolving `Expression` and `Selector` instances. Pointers format: `.../parameters/{key}`.

3. **Dual-Mode Condition Expression Extraction**:
   - Criterion `condition` strings in both `step.successCriteria` and action `criteria` can contain embedded expressions (`{$...}`) or bare expressions (`$steps.x.outputs.y == 1`).
   - `ExpressionWalker` scans conditions for embedded expressions and keyword-anchored runtime expression tokens across the 13 Arazzo root keywords (`$inputs`, `$outputs`, `$steps`, `$workflows`, `$sourceDescriptions`, `$components`, `$response`, `$request`, `$url`, `$method`, `$statusCode`, `$message`, `$self`).
   - Each extracted token is yielded as an `ExpressionSite` targeting `.../criteria/{ci}/condition`, allowing standard downstream rules to validate targets without syntax errors caused by comparison operators.
   - Criterion `context` strings are similarly extracted and yielded targeting `.../criteria/{ci}/context`.

4. **Step vs Workflow Context Isolation**:
   - Step-level action sites carry context `'onSuccess'` (for `onSuccess`) or `'onFailure'` (for `onFailure`, `onTimeout`, `onCancel`) with `$currentStepId` set to the owning step ID. `ExpressionSite::allowsRuntimeHttpReference()` returns `true`, allowing `$response` and `$statusCode`.
   - Workflow-level action sites carry dedicated contexts `'wf.successActions'` and `'wf.failureActions'` with `$currentStepId = null`. `allowsRuntimeHttpReference()` returns `false`, causing `ExpressionContextMisuseRule` to flag any illegal `$response` or `$statusCode` references in workflow actions.
   - Reusable action items (`$action instanceof Reusable`) and reusable parameter references (`$param instanceof Reusable`) are skipped by `ExpressionWalker` because they are validated by dedicated rules (`ActionReusableRefResolvesRule`, `ParameterReusableRefResolvesRule`).

## Consequences

- Documents with unresolved step/workflow/input references or misplaced HTTP references inside actions fail `arazzo validate` with exact JSON Pointers.
- Valid documents continue to pass with zero false positives.
- Reusable references and step-level/workflow-level references behave strictly according to Arazzo 1.1 specification §5.8 and §5.9.
