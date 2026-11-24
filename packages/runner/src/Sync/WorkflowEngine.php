<?php

declare(strict_types=1);

namespace Alama\Arazzo\Runner\Sync;

use Alama\Arazzo\Contracts\Spec\Action\FailureAction;
use Alama\Arazzo\Contracts\Spec\Action\FailureEndAction;
use Alama\Arazzo\Contracts\Spec\Action\FailureGotoAction;
use Alama\Arazzo\Contracts\Spec\Action\RetryAction;
use Alama\Arazzo\Contracts\Spec\Action\SubWorkflowFailureAction;
use Alama\Arazzo\Contracts\Spec\Action\SubWorkflowSuccessAction;
use Alama\Arazzo\Contracts\Spec\Action\SuccessAction;
use Alama\Arazzo\Contracts\Spec\Action\SuccessEndAction;
use Alama\Arazzo\Contracts\Spec\Action\SuccessGotoAction;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Enum\StepStatus;
use Alama\Arazzo\Contracts\Spec\Expression;
use Alama\Arazzo\Contracts\Spec\Reusable;
use Alama\Arazzo\Contracts\Spec\Step;
use Alama\Arazzo\Contracts\Spec\Workflow;
use Alama\Arazzo\Contracts\State\ExecutionState;
use Alama\Arazzo\Evaluation\EvaluationEngineInterface;
use Alama\Arazzo\Expression\Data\EvaluationContext;
use Alama\Arazzo\Runner\Exceptions\ExecutionError;
use Alama\Arazzo\Runner\Exceptions\GotoTargetNotFoundException;
use Alama\Arazzo\Runner\Exceptions\StepBudgetExceededException;
use Alama\Arazzo\Runner\Execution\Data\Transition;
use Alama\Arazzo\Runtime\Policy\RetryPolicy;
use Alama\Arazzo\Runtime\State\Data\ExecutionContext;

/** Chooses the next execution state. It intentionally knows nothing about queues, locks, storage, or events.
 *
 * @internal stays out of the advertised contract; not part of the public API surface
 */
final class WorkflowEngine
{
    private RetryPolicy $retryPolicy;

    private readonly WorkflowNavigator $navigator;

    /**
     * @param  int|RetryPolicy|null  $maxRetryAttempts  Accepts the legacy int ceiling,
     *                                                  a full RetryPolicy, or null for defaults — keeping positional and
     *                                                  named-argument call sites (`maxRetryAttempts:`) source-compatible.
     * @param  float  $retryBackoffMultiplier  Legacy scalar tuning, honored when no
     *                                         explicit RetryPolicy is supplied.
     */
    public function __construct(
        private EvaluationEngineInterface $evaluationEngine,
        int|null|RetryPolicy $maxRetryAttempts = null,
        private float $retryBackoffMultiplier = 1.0,
    ) {
        $this->retryPolicy = $maxRetryAttempts instanceof RetryPolicy
            ? $maxRetryAttempts
            : new RetryPolicy(maxAttempts: $maxRetryAttempts ?? 10, backoffMultiplier: $retryBackoffMultiplier);
        $this->navigator = new WorkflowNavigator();
    }

    /**
     * Apply a completed attempt. The adapter is responsible for doing the actual
     * I/O and storing its result in $state before calling this method.
     */
    public function transition(ArazzoDocument $document, Workflow $workflow, Step $step, ExecutionState|ExecutionContext $incoming, bool $criteriaMet, bool $suspended = false): Transition
    {
        // The engine stays canonical on ExecutionState; the richer
        // ExecutionContext facade normalizes into it at the boundary.
        $state = $incoming instanceof ExecutionContext ? $incoming->toExecutionState() : $incoming;

        if ($suspended) {
            return Transition::suspend($state->withCurrentStep($step->stepId));
        }
        if ($state->stepsSpent >= $state->maxSteps) {
            throw StepBudgetExceededException::exceeded($state->executionId, $state->maxSteps);
        }

        $state = $this->recordAttemptOutcome($state, $step, $criteriaMet);
        [$matched, $state] = $this->evaluateActions($document, $workflow, $step, $state, $criteriaMet);

        return $matched ?? $this->fallbackTransition($workflow, $step, $state, $criteriaMet);
    }

    /**
     * Evaluates the workflow-level `outputs` expressions against the final
     * step results. Unresolvable expressions evaluate to null instead of
     * failing the run.
     *
     * @return array<string, mixed>
     */
    public function evaluateWorkflowOutputs(Workflow $workflow, ExecutionState|ExecutionContext $state): array
    {
        if ($workflow->outputs->isEmpty()) {
            return [];
        }

        $context = $state instanceof ExecutionContext ? $state->toWorkflowContext() : $state->toContext();
        $outputs = [];

        foreach ($workflow->outputs as $name => $expression) {
            try {
                $outputs[$name] = $expression instanceof Expression
                    ? $this->evaluationEngine->evaluate($expression, new EvaluationContext($context))
                    : $expression;
            } catch (\Throwable) {
                $outputs[$name] = null;
            }
        }

        return $outputs;
    }

    /**
     * Picks the next runnable step id for adapter-side loops that drive one
     * step per unit (queue jobs, CLI ticks). Public because adapters own the
     * driving loop; the decision itself stays here.
     */
    public function nextRunnableStep(Workflow $workflow, ExecutionState $state, ?string $completed): ?string
    {
        return $this->navigator->nextRunnable($workflow, $state, $completed ?? '');
    }

    private function recordAttemptOutcome(ExecutionState $state, Step $step, bool $criteriaMet): ExecutionState
    {
        $state = $state->spendStep()->withCurrentStep($step->stepId);
        $record = self::stepRecord($state->stepResults[$step->stepId] ?? null);
        $record['status'] = $criteriaMet ? StepStatus::Succeeded->value : StepStatus::Failed->value;

        return $state->withStepResult($step->stepId, $record);
    }

    /**
     * @return array{0: ?Transition, 1: ExecutionState}
     */
    private function evaluateActions(
        ArazzoDocument $document,
        Workflow $workflow,
        Step $step,
        ExecutionState $state,
        bool $criteriaMet,
    ): array {
        foreach ($this->navigator->resolveActions($document, $workflow, $step, $criteriaMet) as $action) {
            $criteriaResults = $this->evaluationEngine->evaluateCriteria($action->criteria, $step, $state->toContext(), $document);
            if (!$criteriaResults->criteriaMet()) {
                continue;
            }

            $this->assertActionTargetExclusive($action);
            [$transition, $state] = $this->applyAction($document, $workflow, $step, $state, $action);
            if ($transition !== null) {
                return [$transition, $state];
            }
        }

        return [null, $state];
    }

    /**
     * @return array{0: ?Transition, 1: ExecutionState}
     */
    private function applyAction(
        ArazzoDocument $document,
        Workflow $workflow,
        Step $step,
        ExecutionState $state,
        SuccessAction|FailureAction $action,
    ): array {
        if ($action instanceof RetryAction) {
            return $this->applyRetry($document, $workflow, $step, $state, $action);
        }
        if ($action instanceof SuccessGotoAction || $action instanceof FailureGotoAction) {
            return [$this->applyGoto($document, $workflow, $step, $state, $action), $state];
        }
        if ($action instanceof SuccessEndAction || $action instanceof FailureEndAction) {
            return [Transition::end($state, $action instanceof SuccessEndAction ? 'succeeded' : 'failed'), $state];
        }
        if ($action instanceof SubWorkflowSuccessAction || $action instanceof SubWorkflowFailureAction) {
            $this->navigator->assertCanEnter($state, $action->workflowId);

            // Invocation is deliberately represented as a transition. An adapter can run
            // the nested engine using the shared budget and stack before resuming here.
            return [Transition::invoke($state, $action->workflowId), $state];
        }

        return [null, $state];
    }

    /**
     * @return array{0: ?Transition, 1: ExecutionState}
     */
    private function applyRetry(
        ArazzoDocument $document,
        Workflow $workflow,
        Step $step,
        ExecutionState $state,
        RetryAction $action,
    ): array {
        $attemptsSoFar = $state->attemptFor($step->stepId);
        if ($this->retryPolicy->isExhausted($attemptsSoFar, $action->retryLimit)) {
            // Observable exhaustion marker; adapters may surface it.
            $nextState = $state->withErrorEntry([
                'type' => 'retry_exhausted',
                'stepId' => $step->stepId,
                'attempts' => $attemptsSoFar,
            ]);

            return [null, $nextState];
        }

        $targetWorkflowId = $action->workflowId ?? $workflow->workflowId;
        $targetStepId = $action->stepId ?? $step->stepId;
        $this->navigator->assertTargetExists($document, $targetWorkflowId, $targetStepId);

        $next = $state->withStepAttempt($step->stepId)->withWorkflow($targetWorkflowId)->withCurrentStep($targetStepId);
        $delay = $this->retryPolicy->calculateDelay($action, $step, $state->toContext(), $attemptsSoFar + 1);

        return [Transition::retry($next, $targetStepId, $delay, $targetWorkflowId), $next];
    }

    private function applyGoto(
        ArazzoDocument $document,
        Workflow $workflow,
        Step $step,
        ExecutionState $state,
        SuccessGotoAction|FailureGotoAction $action,
    ): Transition {
        $targetWorkflowId = $action->workflowId ?? $workflow->workflowId;
        if ($document->findWorkflow($targetWorkflowId) === null) {
            throw GotoTargetNotFoundException::unknownWorkflow($action->name, $targetWorkflowId);
        }
        if ($action->stepId !== null) {
            $this->navigator->assertTargetExists($document, $targetWorkflowId, $action->stepId);
        }

        // Steps topologically between the goto source and target are skipped:
        // no further transition (and no side effect) targets them while active.
        $skipped = $this->navigator->stepsBetween($workflow, $step->stepId, $action->stepId);
        $gotoState = $state
            ->withWorkflow($targetWorkflowId)
            ->withCurrentStep($action->stepId)
            ->withSkippedSteps($skipped);

        $gotoState = $this->bindGotoParameters($action, $step, $gotoState);

        return Transition::goto($gotoState, $action->stepId, $targetWorkflowId);
    }

    private function bindGotoParameters(
        SuccessGotoAction|FailureGotoAction $action,
        Step $step,
        ExecutionState $gotoState,
    ): ExecutionState {
        // 1.1: goto parameters bind values into the target workflow input scope.
        foreach ($action->parameters as $parameter) {
            if ($parameter instanceof Reusable) {
                continue; // component defaults resolve inside the target scope
            }

            $value = $parameter->value instanceof Expression
                ? $this->evaluationEngine->evaluate($parameter->value, new EvaluationContext($gotoState->toContext(), $step->stepId))
                : $parameter->value;

            $inputs = $gotoState->inputs;
            $inputs[$parameter->name] = $value;
            $gotoState = $gotoState->withInputs($inputs);
        }

        return $gotoState;
    }

    private function fallbackTransition(Workflow $workflow, Step $step, ExecutionState $state, bool $criteriaMet): Transition
    {
        if (!$criteriaMet) {
            return Transition::end($state, 'failed');
        }

        $next = $this->navigator->nextRunnable($workflow, $state, $step->stepId);

        return $next === null
            ? Transition::end($state, 'succeeded')
            : Transition::next($state->withCurrentStep($next), $next);
    }

    private function assertActionTargetExclusive(mixed $action): void
    {
        if (!($action instanceof SuccessGotoAction || $action instanceof FailureGotoAction || $action instanceof RetryAction)) {
            return;
        }
        if ($action->stepId !== null && $action->workflowId !== null) {
            throw ExecutionError::ambiguousActionTarget($action->name);
        }
    }

    /**
     * Persisted step results are name-to-value maps. Non-array entries are
     * dropped and non-string keys filtered so the result matches the typed
     * seam the record is handed to.
     *
     * @return array<string, mixed>
     */
    private static function stepRecord(mixed $record): array
    {
        if (!is_array($record)) {
            return [];
        }

        return array_filter($record, static fn (mixed $key): bool => is_string($key), ARRAY_FILTER_USE_KEY);
    }
}
