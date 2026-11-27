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
use Alama\Arazzo\Runner\Data\Enum\StepRole;
use Alama\Arazzo\Runner\Data\StepExecutionResult;
use Alama\Arazzo\Runner\Data\WorkflowExecutionResult;
use Alama\Arazzo\Runner\Events\RunCompletedEvent;
use Alama\Arazzo\Runner\Events\RunFailedEvent;
use Alama\Arazzo\Runner\Events\RunStartedEvent;
use Alama\Arazzo\Runner\Events\StepExecutedEvent;
use Alama\Arazzo\Runner\Events\StepFailedEvent;
use Alama\Arazzo\Runner\Events\StepRetriedEvent;
use Alama\Arazzo\Runner\Events\StepStartedEvent;
use Alama\Arazzo\Runner\Exceptions\ExecutionError;
use Alama\Arazzo\Runner\Exceptions\ExecutionTimeoutException;
use Alama\Arazzo\Runner\Exceptions\PreflightFailureException;
use Alama\Arazzo\Runner\Exceptions\UnknownStepException;
use Alama\Arazzo\Runner\Exceptions\UnknownWorkflowException;
use Alama\Arazzo\Runner\Execution\Data\ObservabilityContext;
use Alama\Arazzo\Runner\Execution\Data\Transition;
use Alama\Arazzo\Runner\Execution\Enum\TransitionType;
use Alama\Arazzo\Runner\Policy\ExecutionPolicy;
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

    private ?float $runStartedAt = null;

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
    public function execute(Workflow $workflow, ArazzoDocument $document, array $inputs, ?WorkflowContext $context = null): WorkflowExecutionResult
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

        return $this->withRunClock(
            fn (): WorkflowExecutionResult => $this->executeWithDependencies($workflow, $document, $inputs, ExecutionState::fromContext($context), $executionId),
        );
    }

    /**
     * @param  array<string, mixed>  $inputs
     */
    public function executeStep(
        Workflow $workflow,
        ArazzoDocument $document,
        string $stepId,
        array $inputs = [],
        ?WorkflowContext $context = null,
        ?ExecutionPolicy $policy = null,
    ): WorkflowExecutionResult {
        $targetStep = $workflow->findStep($stepId)
            ?? throw UnknownStepException::forId($stepId, $workflow->steps->stepIds(), $workflow->workflowId);

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

        [$context, $stepSequence] = (new SingleStepSequenceResolver())->resolve($workflow, $targetStep, $inputs, $context, $policy);
        $state = $this->initialState($context);

        $runSpan = $this->tracer->startSpan('arazzo.workflow.run', [
            'execution.id' => $executionId,
            'workflow.id' => $workflow->workflowId,
            'step.target' => $stepId,
        ]);

        $this->logger->info('Workflow single-step execution started.', [
            'executionId' => $executionId,
            'workflowId' => $workflow->workflowId,
            'targetStepId' => $stepId,
            'noDeps' => $policy !== null && $policy->noDeps,
        ]);
        $this->events->dispatch(new RunStartedEvent(
            $executionId,
            $workflow->workflowId,
            $workflow->workflowId,
            $inputs,
            $this->clock->now(),
        ));

        return $this->withRunClock(function () use ($document, $workflow, $stepId, $stepSequence, $state, $runSpan, $executionId): WorkflowExecutionResult {
            try {
                $result = $this->executeStepSequence($document, $workflow, $stepId, $stepSequence, $state);
                $runSpan->setStatus($result->status === ExecutionStatus::Succeeded);

                return $result;
            } catch (Throwable $t) {
                $runSpan->recordException($t);
                $runSpan->setStatus(false, $t->getMessage());
                $this->handleLoopFailure($executionId, $workflow, $t);
            } finally {
                $runSpan->end();
            }
        });
    }

    /**
     * @param  list<string>  $stepSequence
     */
    private function executeStepSequence(
        ArazzoDocument $document,
        Workflow $workflow,
        string $stepId,
        array $stepSequence,
        ExecutionState $state,
    ): WorkflowExecutionResult {
        $results = [];
        foreach ($stepSequence as $currentStepId) {
            $this->assertWithinExecutionDeadline($state->executionId);
            $role = ($currentStepId === $stepId) ? StepRole::Target : StepRole::Dependency;
            [$state, $stepResult, $transition] = $this->runStepIteration($document, $workflow, $currentStepId, $state, $role);
            $this->assertWithinExecutionDeadline($state->executionId);
            $results[$currentStepId] = $stepResult;

            if (!$stepResult->isSucceeded()) {
                if ($role === StepRole::Dependency) {
                    $this->logger->error('Step dependency failed.', [
                        'executionId' => $state->executionId,
                        'workflowId' => $workflow->workflowId,
                        'dependencyId' => $currentStepId,
                    ]);
                    $this->events->dispatch(new RunFailedEvent(
                        $state->executionId,
                        $workflow->workflowId,
                        ExecutionError::dependencyFailed($currentStepId),
                        $this->clock->now(),
                        'execution',
                    ));

                    return new WorkflowExecutionResult(
                        $workflow->workflowId,
                        ExecutionStatus::Failed,
                        [],
                        $results,
                        $state->stepsSpent,
                        $state->workflowCallStack,
                        sequentialFallbacks: ['--step implies sequential execution'],
                    );
                }

                return $this->finishTerminal($workflow, $transition, $state, $results, sequentialFallbacks: ['--step implies sequential execution']);
            }
        }

        $outputs = $this->workflowEngine->evaluateWorkflowOutputs($workflow, $state);
        $this->events->dispatch(new RunCompletedEvent($state->executionId, $workflow->workflowId, $outputs, $this->clock->now()));

        return new WorkflowExecutionResult(
            $workflow->workflowId,
            ExecutionStatus::Succeeded,
            $outputs,
            $results,
            $state->stepsSpent,
            $state->workflowCallStack,
            sequentialFallbacks: ['--step implies sequential execution'],
        );
    }

    /**
     * Runs the transitive `dependsOn` closure once, then the requested workflow.
     *
     * @param  array<string, mixed>  $inputs
     */
    private function executeWithDependencies(Workflow $workflow, ArazzoDocument $document, array $inputs, ExecutionState $handoff, string $executionId): WorkflowExecutionResult
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

                return new WorkflowExecutionResult(
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
    private function executeOnce(Workflow $workflow, ArazzoDocument $document, array $inputs, WorkflowContext $context, string $executionId): WorkflowExecutionResult
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
            $result = $this->executeCanonically($workflow, $document, $context, $executionId);
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

    private function executeCanonically(Workflow $workflow, ArazzoDocument $document, WorkflowContext $context, string $executionId): WorkflowExecutionResult
    {
        $state = $this->initialState($context);
        $currentWorkflow = $workflow;
        $stepId = $workflow->firstStep()?->stepId;
        /** @var array<string, StepExecutionResult> $results */
        $results = [];

        try {
            while ($stepId !== null) {
                $this->assertWithinExecutionDeadline($executionId);
                [$state, $stepResult, $transition] = $this->runStepIteration($document, $currentWorkflow, $stepId, $state);
                $this->assertWithinExecutionDeadline($executionId);
                $results[$stepId] = $stepResult;

                if ($transition->isTerminal()) {
                    return $this->finishTerminal($currentWorkflow, $transition, $state, $results);
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

        return new WorkflowExecutionResult($currentWorkflow->workflowId, ExecutionStatus::Succeeded, $state->outputs, $results, $state->stepsSpent, $state->workflowCallStack);
    }

    private function initialState(WorkflowContext $context): ExecutionState
    {
        $policy = $this->stepExecutor->policy();

        return ExecutionState::fromContext(
            $context,
            maxSteps: $policy->maxSteps(),
            maxWorkflowDepth: $policy->maxWorkflowDepth(),
        );
    }

    /**
     * @return array{0: ExecutionState, 1: StepExecutionResult, 2: Transition}
     */
    private function runStepIteration(
        ArazzoDocument $document,
        Workflow $workflow,
        string $stepId,
        ExecutionState $state,
        StepRole $role = StepRole::Standard,
    ): array {
        $step = $workflow->findStep($stepId);
        assert($step instanceof Step);

        $attempt = $state->attemptFor($stepId) + 1;
        $this->events->dispatch(new StepStartedEvent($state->executionId, $workflow->workflowId, $stepId, $attempt, $this->clock->now()));

        [$stepContext, $success] = $this->executeTracedStep($step, $workflow, $state, $document, $state->executionId);
        $raw = $stepContext->getStepRecord($stepId) ?? [];
        /** @var array<string, mixed> $raw */
        $state = $state->withStepResult($stepId, $raw);

        $error = $success ? null : (
            ($raw['exception'] ?? null) instanceof Throwable
                ? $raw['exception']
                : ExecutionError::stepFailed($stepId)
        );

        $result = new StepExecutionResult(
            $stepId,
            $success,
            $stepContext->getStepOutputs($stepId),
            $error,
            role: $role,
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
        StepExecutionResult $result,
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
                $result->error ?? ExecutionError::stepFailed($result->stepId),
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
     * @param  array<string, StepExecutionResult>  $results
     * @param  list<string>  $sequentialFallbacks
     */
    private function finishTerminal(
        Workflow $workflow,
        Transition $transition,
        ExecutionState $state,
        array $results,
        array $sequentialFallbacks = [],
    ): WorkflowExecutionResult {
        $stepId = $transition->stepId ?? (string) (array_key_last($results) ?? $state->currentStepId);
        if ($transition->isFailed()) {
            $this->logger->warning('Workflow ended in failure.', [
                'executionId' => $state->executionId,
                'workflowId' => $workflow->workflowId,
                'stepId' => $stepId,
            ]);
            $failedResult = $results[$stepId] ?? null;
            $rawResult = $state->stepResults[$stepId] ?? null;
            $category = is_array($rawResult) && is_string($rawResult['failureCategory'] ?? null)
                ? $rawResult['failureCategory']
                : 'criteria';

            $this->events->dispatch(new RunFailedEvent(
                $state->executionId,
                $workflow->workflowId,
                $failedResult->error ?? ExecutionError::workflowFailedAtStep($workflow->workflowId, $stepId),
                $this->clock->now(),
                $category,
            ));

            return new WorkflowExecutionResult($workflow->workflowId, ExecutionStatus::Failed, [], $results, $state->stepsSpent, $state->workflowCallStack, $sequentialFallbacks);
        }

        $outputs = $this->workflowEngine->evaluateWorkflowOutputs($workflow, $state);
        $this->events->dispatch(new RunCompletedEvent($state->executionId, $workflow->workflowId, $outputs, $this->clock->now()));

        return new WorkflowExecutionResult($workflow->workflowId, ExecutionStatus::Succeeded, $outputs, $results, $state->stepsSpent, $state->workflowCallStack, $sequentialFallbacks);
    }

    private function handleLoopFailure(string $executionId, Workflow $workflow, Throwable $exception): never
    {
        $category = match (true) {
            $exception instanceof ExecutionTimeoutException => 'transport',
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

    private function assertWithinExecutionDeadline(string $executionId): void
    {
        $timeoutSeconds = $this->stepExecutor->policy()->executionTimeout();
        if ($timeoutSeconds === null || $this->runStartedAt === null) {
            return;
        }

        $elapsed = $this->currentTimestamp() - $this->runStartedAt;
        if ($elapsed > $timeoutSeconds) {
            throw ExecutionTimeoutException::exceeded($executionId, $timeoutSeconds, $elapsed);
        }
    }

    /**
     * @param  callable(): WorkflowExecutionResult  $fn
     */
    private function withRunClock(callable $fn): WorkflowExecutionResult
    {
        $previousStart = $this->runStartedAt;
        $this->runStartedAt ??= $this->currentTimestamp();

        try {
            return $fn();
        } finally {
            $this->runStartedAt = $previousStart;
        }
    }

    private function currentTimestamp(): float
    {
        return (float) $this->clock->now()->format('U.u');
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
}
