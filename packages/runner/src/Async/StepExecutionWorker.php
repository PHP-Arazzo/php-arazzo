<?php

declare(strict_types=1);

namespace Alama\Arazzo\Runner\Async;

use Alama\Arazzo\Contracts\Exceptions\SchemaValidationException;
use Alama\Arazzo\Contracts\Interfaces\LockManagerInterface;
use Alama\Arazzo\Contracts\Interfaces\OperationExecutorPluginInterface;
use Alama\Arazzo\Contracts\Interfaces\QueueDriverInterface;
use Alama\Arazzo\Contracts\Observability\SpanInterface;
use Alama\Arazzo\Contracts\Observability\TracerInterface;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Enum\ExecutionStatus;
use Alama\Arazzo\Contracts\Spec\Enum\StepStatus;
use Alama\Arazzo\Contracts\Spec\Step;
use Alama\Arazzo\Contracts\Spec\Workflow;
use Alama\Arazzo\Contracts\State\ExecutionState;
use Alama\Arazzo\Contracts\State\WorkflowContext;
use Alama\Arazzo\Engine\Exceptions\ExecutionException;
use Alama\Arazzo\Engine\Execution\StepParameterMerger;
use Alama\Arazzo\Evaluation\EvaluationEngineInterface;
use Alama\Arazzo\Expression\Data\EvaluationContext;
use Alama\Arazzo\Runner\Data\ExecuteStepJob;
use Alama\Arazzo\Runner\DefinitionStoreInterface;
use Alama\Arazzo\Runner\Events\CorrelationPendingEvent;
use Alama\Arazzo\Runner\Events\RunCompletedEvent;
use Alama\Arazzo\Runner\Events\RunFailedEvent;
use Alama\Arazzo\Runner\Events\StepExecutedEvent;
use Alama\Arazzo\Runner\Events\StepFailedEvent;
use Alama\Arazzo\Runner\Events\StepStartedEvent;
use Alama\Arazzo\Runner\Exceptions\PreflightFailureException;
use Alama\Arazzo\Runner\Execution\Data\AsyncPorts;
use Alama\Arazzo\Runner\Execution\Data\RunControlFlow;
use Alama\Arazzo\Runner\Execution\Enum\TransitionType;
use Alama\Arazzo\Runner\Execution\SourcePolicyResolver;
use Alama\Arazzo\Runner\ExecutionStoreInterface;
use Alama\Arazzo\Runner\Policy\ExecutionPolicy;
use Alama\Arazzo\Runner\Preflight\Preflight;
use Alama\Arazzo\Runner\Registries\OperationExecutorRegistry;
use Alama\Arazzo\Runner\Sync\WorkflowEngine;
use LogicException;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * @internal stays out of the advertised contract; not part of the public API surface
 */
class StepExecutionWorker
{
    private ExecutionStoreInterface $store;

    private DefinitionStoreInterface $definitions;

    private LockManagerInterface $lockManager;

    private EventDispatcherInterface $events;

    private LoggerInterface $logger;

    private TracerInterface $tracer;

    private ClockInterface $clock;

    private ?Preflight $preflight;

    private WorkflowEngine $workflowEngine;

    private QueueDriverInterface $queueDriver;

    private ExecutionPolicy $policy;

    public function __construct(
        AsyncPorts $ports,
        private EvaluationEngineInterface $evaluationEngine,
        private ?OperationExecutorRegistry $executors,
        RunControlFlow $controlFlow,
    ) {
        $this->store = $ports->store;
        $this->definitions = $ports->definitions;
        $this->lockManager = $ports->lockManager;
        $this->workflowEngine = $controlFlow->workflowEngine;
        $this->queueDriver = $controlFlow->queueDriver;
        $this->preflight = $controlFlow->preflight;
        $this->policy = $controlFlow->policy;
        $this->events = $controlFlow->observability->events;
        $this->logger = $controlFlow->observability->logger;
        $this->tracer = $controlFlow->observability->tracer;
        $this->clock = $controlFlow->observability->clock;
    }

    public function handle(ExecuteStepJob $job): void
    {
        $step = $job->step;
        $executionId = $job->context->getExecutionId();

        $span = $this->tracer->startSpan('arazzo.step.execute', [
            'execution.id' => $executionId,
            'workflow.id' => (string) $job->context->getWorkflowId(),
            'step.id' => $step->stepId,
        ]);

        try {
            $this->handleUnderSpan($job, $span);
        } finally {
            $span->end();
        }
    }

    private function handleUnderSpan(ExecuteStepJob $job, SpanInterface $span): void
    {
        $step = $job->step;
        $executionId = $job->context->getExecutionId();

        $lockKey = "execution_lock_{$executionId}";

        $this->lockManager->acquire($lockKey, 30, function () use ($step, $job, $executionId, $span): void {
            $context = $this->reconcileWithPersistedState($job->context, $executionId);

            try {
                $this->documentPreflightValidation($context);

                if ($context->getStepStatus($step->stepId) === StepStatus::Succeeded) {
                    return;
                }

                $document = $this->definitions->find($context->getDefinitionId());
                if ($document === null) {
                    $this->logger->error('Workflow definition not found for execution.', [
                        'executionId' => $executionId,
                        'definitionId' => $context->getDefinitionId(),
                    ]);

                    return;
                }

                $workflow = $this->findWorkflow($document, $context->getWorkflowId());
                if ($workflow === null) {
                    $this->logger->error('Workflow not found in definition for execution.', [
                        'executionId' => $executionId,
                        'workflowId' => $context->getWorkflowId(),
                    ]);

                    return;
                }

                $context = $context->withStepAttemptIncremented($step->stepId);
                $attempt = $context->getStepAttempts($step->stepId);
                $this->events->dispatch(new StepStartedEvent(
                    $executionId,
                    $context->getWorkflowId() ?? '',
                    $step->stepId,
                    $attempt,
                    $this->clock->now(),
                ));

                $step = StepParameterMerger::merge($step, $workflow);

                $executor = $this->findExecutor($step, $document);
                if ($executor === null) {
                    $this->failUnresolvedExecutor($step);
                }

                $sourcePolicy = (new SourcePolicyResolver())->resolveForStep($step, $document, $this->policy);
                $outcome = $executor->execute($step, $context->withActiveSourcePolicy($sourcePolicy), $document, $executionId);

                if ($outcome->suspended) {
                    $newContext = $context->withStepStatus($step->stepId, StepStatus::Suspended);
                    $this->store->saveState($executionId, $newContext->toArray(), $this->policy->stateTtlSeconds);
                    $this->store->markStarted($executionId, $newContext->getDefinitionId(), $workflow->workflowId);
                    $this->logger->info('Step suspended awaiting external correlation.', [
                        'executionId' => $executionId,
                        'stepId' => $step->stepId,
                    ]);

                    if ($step->target->action === 'receive' && $step->target->correlationId !== null && $step->target->channelPath !== null) {
                        $evaluated = $this->evaluationEngine->evaluate($step->target->correlationId, new EvaluationContext($context, $step->stepId));
                        $correlationIdValue = is_scalar($evaluated) ? (string) $evaluated : '';
                        $this->events->dispatch(new CorrelationPendingEvent(
                            $executionId,
                            $context->getWorkflowId() ?? '',
                            $step->stepId,
                            $correlationIdValue,
                            $step->target->channelPath,
                            $this->clock->now(),
                        ));
                    }

                    return;
                }

                $contextWithResult = $context->withStepResult($step->stepId, [
                    'statusCode' => $outcome->statusCode,
                    'request' => $outcome->request ?? [],
                    'response' => ['statusCode' => $outcome->statusCode, 'headers' => $outcome->responseHeaders, 'body' => $outcome->responseBody],
                    'rawBody' => $outcome->rawBody,
                    'contentType' => $outcome->contentType,
                    'failureCategory' => $outcome->failureCategory,
                    'outputs' => $outcome->outputs,
                    'inputs' => $outcome->inputs,
                    'attempts' => $attempt,
                ]);

                $criteriaMet = $this->evaluationEngine->evaluateSuccessCriteria($step, $contextWithResult, $document);

                $this->store->markStarted($executionId, $contextWithResult->getDefinitionId(), $workflow->workflowId);

                $persisted = $this->store->loadState($executionId);
                $state = $persisted !== null && isset($persisted['executionId'])
                    ? ExecutionState::fromArray($persisted)
                    : ExecutionState::start(
                        $executionId,
                        $contextWithResult->getDefinitionId(),
                        $workflow->workflowId,
                        $contextWithResult->getInputs(),
                        maxSteps: $this->policy->maxSteps(),
                        maxWorkflowDepth: $this->policy->maxWorkflowDepth(),
                        components: $contextWithResult->getComponents(),
                    );
                foreach ($contextWithResult->getSteps() as $completedStepId => $completedResult) {
                    $state = $state->withStepResult($completedStepId, self::stepRecord($completedResult));
                }
                $state = $state->restoreBudget($contextWithResult->getStepsSpent(), $contextWithResult->getWorkflowCallStack() ?: [$workflow->workflowId]);
                foreach ($this->attemptsFrom($contextWithResult) as $attemptedStepId => $attempts) {
                    while ($state->attemptFor($attemptedStepId) < $attempts - 1) {
                        $state = $state->withStepAttempt($attemptedStepId);
                    }
                }
                $record = self::stepRecord($contextWithResult->getSteps()[$step->stepId] ?? null);
                $state = $state->withStepResult($step->stepId, $record);
                $transition = $this->workflowEngine->transition($document, $workflow, $step, $state, $criteriaMet);
                $nextState = $transition->state;
                assert($nextState instanceof ExecutionState);
                $this->store->saveState($executionId, $nextState->toContext()->toArray(), $this->policy->stateTtlSeconds);

                $span->setAttribute('criteria.met', $criteriaMet);

                if ($transition->isTerminal()) {
                    $succeeded = $transition->status === 'succeeded';
                    $span->setStatus($succeeded, $succeeded ? null : "Step '{$step->stepId}' failed criteria");
                    $this->store->markCompleted($executionId, $succeeded ? ExecutionStatus::Succeeded : ExecutionStatus::Failed);

                    if ($succeeded) {
                        $this->events->dispatch(new RunCompletedEvent(
                            $executionId,
                            $transition->state->workflowId,
                            $this->workflowEngine->evaluateWorkflowOutputs($workflow, $transition->state),
                            $this->clock->now(),
                        ));
                    } else {
                        $this->events->dispatch(new RunFailedEvent(
                            $executionId,
                            $transition->state->workflowId,
                            new RuntimeException("Workflow '{$transition->state->workflowId}' failed at step '{$step->stepId}'."),
                            $this->clock->now(),
                        ));
                    }
                } elseif ($transition->type !== TransitionType::Suspend) {
                    $targetWorkflowId = $transition->workflowId ?? $workflow->workflowId;
                    $targetWorkflow = $this->findWorkflow($document, $targetWorkflowId);

                    if ($targetWorkflow === null) {
                        $this->logger->error('Transition targeted unknown workflow.', [
                            'executionId' => $executionId,
                            'workflowId' => $targetWorkflowId,
                        ]);
                        $this->events->dispatch(new RunFailedEvent(
                            $executionId,
                            $targetWorkflowId,
                            new LogicException("Unknown workflow '{$targetWorkflowId}'."),
                            $this->clock->now(),
                        ));

                        return;
                    }

                    $targetStep = $transition->stepId !== null
                        ? $this->findStep($targetWorkflow, $transition->stepId)
                        : ($targetWorkflow->steps[0] ?? null);

                    if ($targetStep !== null) {
                        $dispatchState = $transition->state;
                        assert($dispatchState instanceof ExecutionState);
                        $this->queueDriver->dispatch(new ExecuteStepJob($targetStep, $dispatchState->toContext()), $transition->delaySeconds);
                    }
                }

                $this->events->dispatch(new StepExecutedEvent(
                    $executionId,
                    $workflow->workflowId,
                    $step->stepId,
                    $outcome->statusCode ?? 0,
                    $outcome->outputs,
                    $criteriaMet,
                    $this->clock->now(),
                ));
            } catch (Throwable $t) {
                $span->recordException($t);
                $span->setStatus(false, $t->getMessage());
                $category = match (true) {
                    $t instanceof PreflightFailureException => 'authoring',
                    $t instanceof SchemaValidationException => 'schema',
                    default => 'execution',
                };
                $this->logger->error('Step execution failed with exception.', [
                    'executionId' => $executionId,
                    'stepId' => $step->stepId,
                    'category' => $category,
                    'exception' => $t::class,
                    'message' => $t->getMessage(),
                ]);
                $this->events->dispatch(new StepFailedEvent(
                    $executionId,
                    $context->getWorkflowId() ?? '',
                    $step->stepId,
                    $t,
                    $this->clock->now(),
                    $category,
                ));
                $this->events->dispatch(new RunFailedEvent(
                    $executionId,
                    $context->getWorkflowId() ?? '',
                    $t,
                    $this->clock->now(),
                    $category,
                ));
                throw $t;
            }
        });
    }

    private function reconcileWithPersistedState(WorkflowContext $context, string $executionId): WorkflowContext
    {
        $persisted = $this->store->loadState($executionId);

        return $persisted === null
            ? $context
            : WorkflowContext::reconciled($context, $persisted, $executionId);
    }

    private function findWorkflow(ArazzoDocument $document, ?string $workflowId): ?Workflow
    {
        return array_find($document->workflows, fn ($workflow) => $workflow->workflowId === $workflowId);
    }

    private function findExecutor(Step $step, ArazzoDocument $document): ?OperationExecutorPluginInterface
    {
        return $this->executors?->resolve($step, $document);
    }

    private function failUnresolvedExecutor(Step $step): never
    {
        if ($this->executors === null) {
            throw new ExecutionException('No operation executor registry configured; register a protocol executor plugin.');
        }

        throw new LogicException("No OperationExecutorPluginInterface supports step '{$step->stepId}'.");
    }

    /** @return array<string, int> */
    private function attemptsFrom(WorkflowContext $context): array
    {
        $attempts = [];
        foreach ($context->getSteps() as $id => $step) {
            $attempt = self::stepRecord($step)['attempts'] ?? null;
            $attempts[$id] = is_scalar($attempt) ? (int) $attempt : 0;
        }

        return $attempts;
    }

    /**
     * @return array<string, mixed>
     */
    private static function stepRecord(mixed $record): array
    {
        if (!is_array($record)) {
            return [];
        }

        return array_filter($record, static fn (mixed $key): bool => is_string($key), ARRAY_FILTER_USE_KEY);
    }

    private function findStep(Workflow $workflow, string $stepId): ?Step
    {
        return array_find($workflow->steps, fn ($step) => $step->stepId === $stepId);
    }

    private function documentPreflightValidation(WorkflowContext $context): void
    {
        if ($this->preflight !== null && $context->getSteps() === []) {
            $documentForPreflight = $this->definitions->find($context->getDefinitionId());
            if ($documentForPreflight !== null) {
                $preflightResult = $this->preflight->validate($documentForPreflight);
                if (!$preflightResult->isValid()) {
                    throw new PreflightFailureException(
                        'Preflight validation failed with '.count($preflightResult->errors).' error(s).',
                        $preflightResult,
                    );
                }
            }
        }
    }
}
