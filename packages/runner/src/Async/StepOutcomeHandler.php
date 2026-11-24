<?php

declare(strict_types=1);

namespace Alama\Arazzo\Runner\Async;

use Alama\Arazzo\Contracts\Dependency\DependencyAnalyzer;
use Alama\Arazzo\Contracts\Dependency\DependencyGraph;
use Alama\Arazzo\Contracts\Interfaces\QueueDriverInterface;
use Alama\Arazzo\Contracts\Spec\Action\SubWorkflowFailureAction;
use Alama\Arazzo\Contracts\Spec\Action\SubWorkflowSuccessAction;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Enum\ExecutionStatus;
use Alama\Arazzo\Contracts\Spec\Enum\StepStatus;
use Alama\Arazzo\Contracts\Spec\Expression;
use Alama\Arazzo\Contracts\Spec\Reusable;
use Alama\Arazzo\Contracts\Spec\Selector;
use Alama\Arazzo\Contracts\Spec\Step;
use Alama\Arazzo\Contracts\Spec\Workflow;
use Alama\Arazzo\Contracts\State\ExecutionState;
use Alama\Arazzo\Contracts\State\WorkflowContext;
use Alama\Arazzo\Evaluation\EvaluationEngineInterface;
use Alama\Arazzo\Expression\Data\EvaluationContext;
use Alama\Arazzo\Runner\Data\ExecuteStepJob;
use Alama\Arazzo\Runner\Events\CorrelationPendingEvent;
use Alama\Arazzo\Runner\Events\RunCompletedEvent;
use Alama\Arazzo\Runner\Events\RunFailedEvent;
use Alama\Arazzo\Runner\Events\StepRetriedEvent;
use Alama\Arazzo\Runner\Exceptions\ExecutionError;
use Alama\Arazzo\Runner\Execution\Data\RunControlFlow;
use Alama\Arazzo\Runner\Execution\Data\Transition;
use Alama\Arazzo\Runner\Execution\Enum\TransitionType;
use Alama\Arazzo\Runner\ExecutionStoreInterface;
use Alama\Arazzo\Runner\Policy\ExecutionPolicy;
use Alama\Arazzo\Runner\Sync\WorkflowEngine;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;

/**
 * Queue/sync adapter around the canonical {@see WorkflowEngine}.
 *
 * All control-flow decisions come from the engine as Transition objects;
 * this class performs only the side effects those transitions describe:
 * persisting state, dispatching follow-up jobs, marking lifecycle status,
 * emitting PSR-14 events, and writing structured PSR-3 diagnostics.
 *
 * @internal stays out of the advertised contract; not part of the public API surface
 */
class StepOutcomeHandler
{
    private EventDispatcherInterface $events;

    private LoggerInterface $logger;

    private ClockInterface $clock;

    private QueueDriverInterface $queueDriver;

    private WorkflowEngine $workflowEngine;

    private ExecutionPolicy $policy;

    public function __construct(
        private ExecutionStoreInterface $store,
        RunControlFlow $controlFlow,
        private SubWorkflowInvoker $invoker,
        private EvaluationEngineInterface $evaluationEngine,
    ) {
        $this->queueDriver = $controlFlow->queueDriver;
        $this->workflowEngine = $controlFlow->workflowEngine;
        $this->events = $controlFlow->observability->events;
        $this->logger = $controlFlow->observability->logger;
        $this->clock = $controlFlow->observability->clock;
        $this->policy = $controlFlow->policy;
    }

    public function handle(
        ArazzoDocument $document,
        Workflow $workflow,
        Step $step,
        WorkflowContext $context,
        string $executionId,
        bool $criteriaMet,
        bool $suspended = false,
    ): void {
        foreach ($step->io->outputs as $name => $value) {
            $resolved = match (true) {
                $value instanceof Selector => $this->evaluationEngine->evaluateSelector($value, $context, $step->stepId),
                $value instanceof Expression => $this->evaluationEngine->evaluate($value, new EvaluationContext($context, $step->stepId)),
                default => $value,
            };
            $context = $context->withStepOutput($step->stepId, $name, $resolved);
        }

        $state = ExecutionState::fromContext($context);
        $transition = $this->workflowEngine->transition($document, $workflow, $step, $state, $criteriaMet, $suspended);

        /** @var array<string, mixed> $error */
        foreach ($transition->state->errors as $error) {
            if (($error['type'] ?? null) === 'retry_exhausted') {
                $this->logger->warning('Step retry limit exhausted.', [
                    'executionId' => $executionId,
                    'stepId' => $error['stepId'],
                    'attempts' => $error['attempts'],
                ]);
            }
        }

        match ($transition->type) {
            TransitionType::Next => $this->applyNext($transition, $workflow, $step, $context, $executionId),
            TransitionType::Retry => $this->applyRetry($transition, $document, $workflow, $step, $context, $executionId),
            TransitionType::Goto => $this->applyGoto($transition, $document, $step, $context, $executionId, $criteriaMet),
            TransitionType::Invoke => $this->applyInvoke($document, $workflow, $step, $context, $executionId),
            TransitionType::End => $this->applyEnd($transition, $workflow, $step, $context, $executionId),
            TransitionType::Suspend => $this->applySuspend($step, $context, $executionId),
        };
    }

    private function applySuspend(
        Step $step,
        WorkflowContext $context,
        string $executionId,
    ): void {
        if (!$step->isActorHandoff()) {
            return;
        }

        $correlationIdValue = $this->resolveCorrelationId($step, $context);
        $channelPath = $step->target->channelPath ?? '';

        $this->store->saveCorrelation(
            $correlationIdValue,
            $executionId,
            $step->stepId,
            $channelPath,
            $this->policy->stateTtlSeconds,
        );

        $this->events->dispatch(new CorrelationPendingEvent(
            $executionId,
            $context->getWorkflowId() ?? '',
            $step->stepId,
            $correlationIdValue,
            $channelPath,
            $this->clock->now(),
        ));
    }

    private function resolveCorrelationId(Step $step, WorkflowContext $context): string
    {
        if ($step->target->correlationId === null) {
            return '';
        }

        $evaluated = $this->evaluationEngine->evaluate($step->target->correlationId, new EvaluationContext($context, $step->stepId));

        return is_scalar($evaluated) ? (string) $evaluated : '';
    }

    private function applyNext(
        Transition $transition,
        Workflow $workflow,
        Step $step,
        WorkflowContext $context,
        string $executionId,
    ): void {
        $newContext = $context->withStepStatus($step->stepId, StepStatus::Succeeded);
        $this->store->saveState($executionId, $newContext->toArray(), $this->policy->stateTtlSeconds);

        assert($transition->stepId !== null);
        $targetStep = $workflow->findStep($transition->stepId);
        assert($targetStep instanceof Step);

        $this->queueDriver->dispatch(new ExecuteStepJob($targetStep, $newContext));
    }

    private function applyRetry(
        Transition $transition,
        ArazzoDocument $document,
        Workflow $workflow,
        Step $step,
        WorkflowContext $context,
        string $executionId,
    ): void {
        $newContext = $context
            ->withStepAttemptIncremented($step->stepId)
            ->withStepStatus($step->stepId, StepStatus::Retrying);

        if ($transition->workflowId !== null && $transition->workflowId !== $context->getWorkflowId()) {
            $newContext = $newContext->withWorkflowId($transition->workflowId);
        }
        if ($transition->stepId !== null && $transition->stepId !== $step->stepId) {
            $newContext = $newContext->withStepStatus($transition->stepId, StepStatus::Pending);
        }

        $this->store->saveState($executionId, $newContext->toArray(), $this->policy->stateTtlSeconds);

        $attempt = $context->getStepAttempts($step->stepId);
        $this->events->dispatch(new StepRetriedEvent(
            $executionId,
            $workflow->workflowId,
            $step->stepId,
            $attempt,
            null,
            $this->clock->now(),
        ));

        $targetWorkflow = $document->findWorkflow($transition->targetWorkflowId($workflow)) ?? $workflow;
        $targetStep = $transition->stepId !== null ? $targetWorkflow->findStep($transition->stepId) : null;
        if ($targetStep !== null) {
            $this->queueDriver->dispatch(new ExecuteStepJob($targetStep, $newContext), $transition->delaySeconds);
        }
    }

    private function applyGoto(
        Transition $transition,
        ArazzoDocument $document,
        Step $step,
        WorkflowContext $context,
        string $executionId,
        bool $criteriaMet,
    ): void {
        $status = $criteriaMet ? StepStatus::Succeeded : StepStatus::Failed;

        $newContext = $context
            ->withWorkflowId($transition->state->workflowId)
            ->withStepStatus($step->stepId, $status)
            ->withInputs($transition->state->inputs)
            ->withSkippedSteps($transition->state->getSkippedSteps());

        $targetWorkflow = $document->findWorkflow($transition->workflowId ?? (string) $context->getWorkflowId());
        assert($targetWorkflow instanceof Workflow);

        if ($transition->stepId === null) {
            $analyzer = new DependencyAnalyzer(new DependencyGraph($targetWorkflow->steps->all()));
            $runnable = $analyzer->getRunnableSteps($newContext);

            $this->store->saveState($executionId, $newContext->toArray(), $this->policy->stateTtlSeconds);
            foreach ($runnable as $runnableStep) {
                $this->queueDriver->dispatch(new ExecuteStepJob($runnableStep, $newContext));
            }

            return;
        }

        $targetStep = $targetWorkflow->findStep($transition->stepId);
        assert($targetStep instanceof Step);

        $newContext = $newContext->withStepStatus($targetStep->stepId, StepStatus::Pending);
        $this->store->saveState($executionId, $newContext->toArray(), $this->policy->stateTtlSeconds);
        $this->queueDriver->dispatch(new ExecuteStepJob($targetStep, $newContext));
    }

    private function applyInvoke(
        ArazzoDocument $document,
        Workflow $workflow,
        Step $step,
        WorkflowContext $context,
        string $executionId,
    ): void {
        $invoke = $this->findSubWorkflowAction($document, $workflow, $step);

        assert($invoke instanceof SubWorkflowSuccessAction || $invoke instanceof SubWorkflowFailureAction);

        $result = $this->invoker->invoke($invoke, $context);

        $stack = $context->getWorkflowCallStack();
        foreach ($result->workflowCallStack as $wfId) {
            if (!in_array($wfId, $stack, true)) {
                $stack[] = $wfId;
            }
        }
        $context = $context->withBudget(max($context->getStepsSpent(), $result->stepsSpent), $stack);

        $context = $context->withWorkflowData($invoke->workflowId, ['inputs' => $result->inputs, 'outputs' => $result->outputs]);
        $context = $context->withStepOutput($step->stepId, $invoke->name, $result->outputs);
        $context = $context->withStepStatus($step->stepId, StepStatus::Succeeded);

        $this->continueFrom($workflow, $context, $executionId);
    }

    private function applyEnd(
        Transition $transition,
        Workflow $workflow,
        Step $step,
        WorkflowContext $context,
        string $executionId,
    ): void {
        $succeeded = $transition->isSucceeded();

        if ($succeeded && $this->store->hasPendingCorrelations($executionId)) {
            $this->store->saveState($executionId, $context->toArray(), $this->policy->stateTtlSeconds);

            return;
        }

        $status = $succeeded ? ExecutionStatus::Succeeded : ExecutionStatus::Failed;
        $this->terminate($context, $executionId, $status);

        if ($succeeded) {
            $this->events->dispatch(new RunCompletedEvent(
                $executionId,
                $workflow->workflowId,
                $this->stepOutputs($context, $step->stepId),
                $this->clock->now(),
            ));
        } else {
            $this->events->dispatch(new RunFailedEvent(
                $executionId,
                $workflow->workflowId,
                ExecutionError::workflowEndedInFailure($workflow->workflowId, $step->stepId),
                $this->clock->now(),
            ));
        }
    }

    private function findSubWorkflowAction(
        ArazzoDocument $document,
        Workflow $workflow,
        Step $step,
    ): SubWorkflowSuccessAction|SubWorkflowFailureAction|null {
        foreach ($step->allActions($workflow) as $action) {
            $resolved = $action instanceof Reusable
                ? $document->components->resolveReusableAction($action)
                : $action;
            if ($resolved instanceof SubWorkflowSuccessAction || $resolved instanceof SubWorkflowFailureAction) {
                return $resolved;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function stepOutputs(WorkflowContext $context, string $stepId): array
    {
        $record = $context->getSteps()[$stepId] ?? null;
        $outputs = is_array($record) ? ($record['outputs'] ?? null) : null;

        return is_array($outputs)
            ? array_filter($outputs, static fn (mixed $key): bool => is_string($key), ARRAY_FILTER_USE_KEY)
            : [];
    }

    private function continueFrom(Workflow $workflow, WorkflowContext $context, string $executionId): void
    {
        $this->store->saveState($executionId, $context->toArray(), $this->policy->stateTtlSeconds);

        $graph = new DependencyGraph($workflow->steps->all());
        $runnable = (new DependencyAnalyzer($graph))->getRunnableSteps($context);

        if ($runnable !== []) {
            foreach ($runnable as $runnableStep) {
                $this->queueDriver->dispatch(new ExecuteStepJob($runnableStep, $context));
            }

            return;
        }

        if (!$this->store->hasPendingCorrelations($executionId)) {
            $this->terminate($context, $executionId, ExecutionStatus::Succeeded);
        }
    }

    private function terminate(WorkflowContext $context, string $executionId, ExecutionStatus $status): void
    {
        $this->store->saveState($executionId, $context->toArray(), $this->policy->stateTtlSeconds);
        $this->store->markCompleted($executionId, $status);
        $this->logger->info('Workflow execution terminated.', [
            'executionId' => $executionId,
            'workflowId' => $context->getWorkflowId(),
            'status' => $status->value,
        ]);
    }
}
