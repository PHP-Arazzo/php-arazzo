<?php

declare(strict_types=1);

namespace Alama\Arazzo\Runner\Sync;

use Alama\Arazzo\Contracts\Exceptions\SchemaValidationException;
use Alama\Arazzo\Contracts\Observability\TracerInterface;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Enum\ExecutionStatus;
use Alama\Arazzo\Contracts\Spec\Step;
use Alama\Arazzo\Contracts\Spec\Workflow;
use Alama\Arazzo\Contracts\State\ExecutionState;
use Alama\Arazzo\Contracts\State\WorkflowContext;
use Alama\Arazzo\Document\DocumentInterface;
use Alama\Arazzo\Engine\Execution\StepParameterMerger;
use Alama\Arazzo\Runner\Data\ExecutionResult;
use Alama\Arazzo\Runner\Data\StepResult;
use Alama\Arazzo\Runner\Events\RunCompletedEvent;
use Alama\Arazzo\Runner\Events\RunFailedEvent;
use Alama\Arazzo\Runner\Events\RunStartedEvent;
use Alama\Arazzo\Runner\Events\StepExecutedEvent;
use Alama\Arazzo\Runner\Events\StepFailedEvent;
use Alama\Arazzo\Runner\Events\StepRetriedEvent;
use Alama\Arazzo\Runner\Events\StepStartedEvent;
use Alama\Arazzo\Runner\Exceptions\ExecutionError;
use Alama\Arazzo\Runner\Exceptions\PreflightFailureException;
use Alama\Arazzo\Runner\Exceptions\UnknownWorkflowException;
use Alama\Arazzo\Runner\Execution\Data\ObservabilityContext;
use Alama\Arazzo\Runner\Execution\Data\Transition;
use Alama\Arazzo\Runner\Execution\Enum\TransitionType;
use Alama\Arazzo\Runner\Preflight\Preflight;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * @internal stays out of the advertised contract; not part of the public API surface
 */
class WorkflowExecutor
{
    private EventDispatcherInterface $events;

    private LoggerInterface $logger;

    private TracerInterface $tracer;

    private ClockInterface $clock;

    private WorkflowPreflightRunner $preflightRunner;

    public function __construct(
        private StepExecutor $stepExecutor,
        private WorkflowEngine $workflowEngine,
        EventDispatcherInterface|ObservabilityContext|null $events = null,
        ?Preflight $preflight = null,
        ?DocumentInterface $document = null,
    ) {
        $observability = $events instanceof ObservabilityContext
            ? $events
            : new ObservabilityContext(events: $events);

        $this->events = $observability->events;
        $this->logger = $observability->logger;
        $this->tracer = $observability->tracer;
        $this->clock = $observability->clock;
        $this->preflightRunner = new WorkflowPreflightRunner($preflight, $document);
    }

    /**
     * @param  array<string, mixed>  $inputs
     */
    public function execute(Workflow $workflow, ArazzoDocument $document, array $inputs, ?WorkflowContext $context = null): ExecutionResult
    {
        // Preflight runs before the first side effect (no events, no state).
        $inputs = $this->preflightRunner->validate($workflow, $document, $inputs);

        $executionId = is_string($inputs['__executionId'] ?? null) ? $inputs['__executionId'] : bin2hex(random_bytes(8));
        $context ??= new WorkflowContext(
            definitionId: $workflow->workflowId,
            inputs: $inputs,
            workflowId: $workflow->workflowId,
            executionId: $executionId,
        );
        $context = $context->withWorkflowData($workflow->workflowId, ['inputs' => $inputs]);

        return $this->executeWithDependencies($workflow, $document, $inputs, ExecutionState::fromContext($context), $executionId);
    }

    /**
     * Runs the transitive `dependsOn` closure once, then the requested workflow.
     *
     * @param  array<string, mixed>  $inputs
     */
    private function executeWithDependencies(Workflow $workflow, ArazzoDocument $document, array $inputs, ExecutionState $handoff, string $executionId): ExecutionResult
    {
        $resolver = new WorkflowDependencyResolver($document->workflows);
        $stepResults = [];

        foreach ($resolver->orderFor($workflow->workflowId) as $dependencyId) {
            if (isset($handoff->workflows[$dependencyId])) {
                continue;
            }

            $dependency = $document->findWorkflow($dependencyId) ?? throw UnknownWorkflowException::forId($dependencyId);
            $result = $this->executeOnce($dependency, $document, [], $handoff->toContext(), $executionId);
            $handoff = $handoff->withWorkflowData($dependencyId, ['outputs' => $result->outputs]);
            $stepResults += $result->stepResults;

            if (!$result->isSucceeded()) {
                $this->logger->error('Workflow dependency failed.', [
                    'executionId' => $executionId,
                    'workflowId' => $workflow->workflowId,
                    'dependencyId' => $dependencyId,
                ]);
                $this->events->dispatch(new RunFailedEvent(
                    $executionId,
                    $workflow->workflowId,
                    ExecutionError::dependencyFailed($dependencyId),
                    $this->clock->now(),
                    'execution',
                ));

                return new ExecutionResult(
                    $workflow->workflowId,
                    ExecutionStatus::Failed,
                    [],
                    $stepResults,
                    $result->stepsSpent,
                    $result->workflowCallStack,
                );
            }
        }

        return $this->executeOnce($workflow, $document, $inputs, $handoff->toContext(), $executionId);
    }

    /** @param array<string, mixed> $inputs */
    private function executeOnce(Workflow $workflow, ArazzoDocument $document, array $inputs, WorkflowContext $context, string $executionId): ExecutionResult
    {
        $runSpan = $this->tracer->startSpan('arazzo.workflow.run', [
            'execution.id' => $executionId,
            'workflow.id' => $workflow->workflowId,
        ]);

        $this->logger->info('Workflow execution started.', [
            'executionId' => $executionId,
            'workflowId' => $workflow->workflowId,
        ]);
        $this->events->dispatch(new RunStartedEvent(
            $executionId,
            $workflow->workflowId,
            $workflow->workflowId,
            $inputs,
            $this->clock->now(),
        ));

        try {
            $result = $this->executeCanonically($workflow, $document, $inputs, $context, $executionId);
            $runSpan->setStatus($result->isSucceeded(), $result->isSucceeded() ? null : "Workflow '{$workflow->workflowId}' failed");

            return $result;
        } catch (Throwable $t) {
            $runSpan->recordException($t);
            $runSpan->setStatus(false, $t->getMessage());
            throw $t;
        } finally {
            $runSpan->end();
        }
    }

    /** @param array<string, mixed> $inputs */
    private function executeCanonically(Workflow $workflow, ArazzoDocument $document, array $inputs, WorkflowContext $context, string $executionId): ExecutionResult
    {
        $state = $this->initialState($workflow, $inputs, $context, $executionId);
        $currentWorkflow = $workflow;
        $stepId = $workflow->firstStep()?->stepId;
        /** @var array<string, StepResult> $results */
        $results = [];

        try {
            while ($stepId !== null) {
                [$state, $stepResult, $transition] = $this->runStepIteration($document, $currentWorkflow, $stepId, $state);
                $results[$stepId] = $stepResult;

                if ($transition->isTerminal()) {
                    return $this->finishTerminal($currentWorkflow, $stepId, $transition, $state, $results);
                }

                if ($transition->workflowId !== null && $transition->workflowId !== $currentWorkflow->workflowId) {
                    $currentWorkflow = $document->findWorkflow($transition->workflowId)
                        ?? throw UnknownWorkflowException::forId($transition->workflowId);
                }
                $stepId = $transition->stepId ?? $currentWorkflow->firstStep()?->stepId;
            }
        } catch (Throwable $t) {
            $this->handleLoopFailure($executionId, $currentWorkflow, $t);
        }

        return new ExecutionResult($currentWorkflow->workflowId, ExecutionStatus::Succeeded, $state->outputs, $results, $state->stepsSpent, $state->workflowCallStack);
    }

    /** @param array<string, mixed> $inputs */
    private function initialState(Workflow $workflow, array $inputs, WorkflowContext $context, string $executionId): ExecutionState
    {
        $policy = $this->stepExecutor->policy();
        $state = ExecutionState::start(
            $executionId,
            $context->getDefinitionId(),
            $workflow->workflowId,
            $inputs,
            maxSteps: $policy->maxSteps(),
            maxWorkflowDepth: $policy->maxWorkflowDepth(),
            components: $context->getComponents(),
            stepsSpent: $context->getStepsSpent(),
            workflowCallStack: $context->getWorkflowCallStack() !== [] ? $context->getWorkflowCallStack() : null,
        );

        return $this->carryWorkflowData($state, $context);
    }

    /**
     * @return array{0: ExecutionState, 1: StepResult, 2: Transition}
     */
    private function runStepIteration(
        ArazzoDocument $document,
        Workflow $workflow,
        string $stepId,
        ExecutionState $state,
    ): array {
        $step = $workflow->findStep($stepId);
        assert($step instanceof Step);

        $attempt = $state->attemptFor($stepId) + 1;
        $this->events->dispatch(new StepStartedEvent($state->executionId, $workflow->workflowId, $stepId, $attempt, $this->clock->now()));

        [$stepContext, $success] = $this->executeTracedStep($step, $workflow, $state, $document, $state->executionId);
        $raw = $stepContext->getStepRecord($stepId) ?? [];
        /** @var array<string, mixed> $raw */
        $state = $state->withStepResult($stepId, $raw);

        $result = new StepResult(
            $stepId,
            $success,
            $stepContext->getStepOutputs($stepId),
            $success ? null : ExecutionError::stepFailed($stepId),
        );

        $transition = $this->workflowEngine->transition($document, $workflow, $step, $state, $success);
        $engineState = $transition->state;
        assert($engineState instanceof ExecutionState);

        $this->dispatchStepOutcome($workflow, $result, $raw, $transition, $engineState);

        return [$engineState, $result, $transition];
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function dispatchStepOutcome(
        Workflow $workflow,
        StepResult $result,
        array $raw,
        Transition $transition,
        ExecutionState $state,
    ): void {
        if ($transition->type === TransitionType::Retry) {
            $this->events->dispatch(new StepRetriedEvent(
                $state->executionId,
                $workflow->workflowId,
                $result->stepId,
                $state->attemptFor($result->stepId),
                null,
                $this->clock->now(),
            ));
        }

        if ($result->isFailed()) {
            $category = is_string($raw['failureCategory'] ?? null) ? $raw['failureCategory'] : 'criteria';
            $this->events->dispatch(new StepFailedEvent(
                $state->executionId,
                $workflow->workflowId,
                $result->stepId,
                ExecutionError::stepFailed($result->stepId),
                $this->clock->now(),
                $category,
            ));

            return;
        }

        $statusCode = is_scalar($raw['statusCode'] ?? null) ? (int) $raw['statusCode'] : 0;
        $this->events->dispatch(new StepExecutedEvent(
            $state->executionId,
            $workflow->workflowId,
            $result->stepId,
            $statusCode,
            $result->outputs,
            true,
            $this->clock->now(),
        ));
    }

    /**
     * @param  array<string, StepResult>  $results
     */
    private function finishTerminal(
        Workflow $workflow,
        string $stepId,
        Transition $transition,
        ExecutionState $state,
        array $results,
    ): ExecutionResult {
        if ($transition->isFailed()) {
            $this->logger->warning('Workflow ended in failure.', [
                'executionId' => $state->executionId,
                'workflowId' => $workflow->workflowId,
                'stepId' => $stepId,
            ]);
            $this->events->dispatch(new RunFailedEvent(
                $state->executionId,
                $workflow->workflowId,
                ExecutionError::workflowFailedAtStep($workflow->workflowId, $stepId),
                $this->clock->now(),
                'criteria',
            ));

            return new ExecutionResult($workflow->workflowId, ExecutionStatus::Failed, [], $results, $state->stepsSpent, $state->workflowCallStack);
        }

        $outputs = $this->workflowEngine->evaluateWorkflowOutputs($workflow, $state);
        $this->events->dispatch(new RunCompletedEvent($state->executionId, $workflow->workflowId, $outputs, $this->clock->now()));

        return new ExecutionResult($workflow->workflowId, ExecutionStatus::Succeeded, $outputs, $results, $state->stepsSpent, $state->workflowCallStack);
    }

    private function handleLoopFailure(string $executionId, Workflow $workflow, Throwable $exception): never
    {
        $category = match (true) {
            $exception instanceof PreflightFailureException => 'authoring',
            $exception instanceof SchemaValidationException => 'schema',
            default => 'execution',
        };
        $this->logger->error('Workflow execution threw an exception.', [
            'executionId' => $executionId,
            'workflowId' => $workflow->workflowId,
            'category' => $category,
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
        ]);
        $this->events->dispatch(new RunFailedEvent($executionId, $workflow->workflowId, $exception, $this->clock->now(), $category));

        throw $exception;
    }

    /**
     * @return array{0: WorkflowContext, 1: bool}
     */
    private function executeTracedStep(Step $step, Workflow $workflow, ExecutionState $state, ArazzoDocument $document, string $executionId): array
    {
        $span = $this->tracer->startSpan('arazzo.step.execute', [
            'execution.id' => $executionId,
            'workflow.id' => $workflow->workflowId,
            'step.id' => $step->stepId,
        ]);

        try {
            [$stepContext, $success] = $this->stepExecutor->execute(
                StepParameterMerger::merge($step, $workflow),
                $state->toContext(),
                $document,
            );
            $span->setAttribute('criteria.met', $success);
            $span->setStatus($success, $success ? null : "Step '{$step->stepId}' failed criteria");

            return [$stepContext, $success];
        } catch (Throwable $t) {
            $span->recordException($t);
            $span->setStatus(false, $t->getMessage());
            throw $t;
        } finally {
            $span->end();
        }
    }

    private function carryWorkflowData(ExecutionState $state, WorkflowContext $context): ExecutionState
    {
        foreach ($context->getWorkflows() as $workflowId => $data) {
            $state = $state->withWorkflowData($workflowId, $data);
        }

        return $state;
    }
}
