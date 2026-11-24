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
use Alama\Arazzo\Runner\Execution\Data\Transition;
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

    private WorkerStateSynchronizer $synchronizer;

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
        $this->synchronizer = new WorkerStateSynchronizer($ports->store);
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
        $executionId = $job->context->getExecutionId();
        $lockKey = "execution_lock_{$executionId}";

        $this->lockManager->acquire($lockKey, 30, function () use ($job, $executionId, $span): void {
            $context = $this->synchronizer->reconcile($job->context, $executionId);

            try {
                $this->executeUnderLock($job->step, $context, $span);
            } catch (Throwable $t) {
                $this->recordStepException($job->step, $context, $span, $t);
            }
        });
    }

    private function executeUnderLock(Step $step, WorkflowContext $context, SpanInterface $span): void
    {
        $executionId = $context->getExecutionId();
        $this->documentPreflightValidation($context);

        if ($context->getStepStatus($step->stepId) === StepStatus::Succeeded) {
            return;
        }

        $resolved = $this->resolveDocumentAndWorkflow($context, $executionId);
        if ($resolved === null) {
            return;
        }
        [$document, $workflow] = $resolved;

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
        $executor = $this->resolveExecutor($step, $document);
        $sourcePolicy = (new SourcePolicyResolver())->resolveForStep($step, $document, $this->policy);
        $outcome = $executor->execute($step, $context->withActiveSourcePolicy($sourcePolicy), $document, $executionId);

        if ($outcome->suspended) {
            $this->handleSuspendedStep($step, $workflow, $context);

            return;
        }

        $contextWithResult = $this->synchronizer->recordStepOutcome($context, $step, $outcome, $attempt);
        $criteriaMet = $this->evaluationEngine->evaluateSuccessCriteria($step, $contextWithResult, $document);
        $this->store->markStarted($executionId, $contextWithResult->getDefinitionId(), $workflow->workflowId);

        $state = $this->synchronizer->hydrateState($contextWithResult, $workflow, $step, $this->policy);
        $transition = $this->workflowEngine->transition($document, $workflow, $step, $state, $criteriaMet);
        $nextState = $transition->state;
        assert($nextState instanceof ExecutionState);
        $this->store->saveState($executionId, $nextState->toContext()->toArray(), $this->policy->stateTtlSeconds);

        $span->setAttribute('criteria.met', $criteriaMet);
        if (!$this->dispatchTransition($document, $workflow, $step, $transition, $span)) {
            return;
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
    }

    /**
     * @return array{0: ArazzoDocument, 1: Workflow}|null
     */
    private function resolveDocumentAndWorkflow(WorkflowContext $context, string $executionId): ?array
    {
        $document = $this->definitions->find($context->getDefinitionId());
        if ($document === null) {
            $this->logger->error('Workflow definition not found for execution.', [
                'executionId' => $executionId,
                'definitionId' => $context->getDefinitionId(),
            ]);

            return null;
        }

        $workflow = $this->findWorkflow($document, $context->getWorkflowId());
        if ($workflow === null) {
            $this->logger->error('Workflow not found in definition for execution.', [
                'executionId' => $executionId,
                'workflowId' => $context->getWorkflowId(),
            ]);

            return null;
        }

        return [$document, $workflow];
    }

    private function handleSuspendedStep(Step $step, Workflow $workflow, WorkflowContext $context): void
    {
        $executionId = $context->getExecutionId();
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
    }

    private function dispatchTransition(
        ArazzoDocument $document,
        Workflow $workflow,
        Step $step,
        Transition $transition,
        SpanInterface $span,
    ): bool {
        if ($transition->isTerminal()) {
            $this->handleTerminalTransition($workflow, $step, $transition, $span);

            return true;
        }

        return $this->dispatchFollowUpStep($document, $workflow, $transition);
    }

    private function handleTerminalTransition(
        Workflow $workflow,
        Step $step,
        Transition $transition,
        SpanInterface $span,
    ): void {
        $executionId = $transition->state->executionId;
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

            return;
        }

        $this->events->dispatch(new RunFailedEvent(
            $executionId,
            $transition->state->workflowId,
            new RuntimeException("Workflow '{$transition->state->workflowId}' failed at step '{$step->stepId}'."),
            $this->clock->now(),
        ));
    }

    private function dispatchFollowUpStep(ArazzoDocument $document, Workflow $workflow, Transition $transition): bool
    {
        $executionId = $transition->state->executionId;
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

            return false;
        }

        $targetStep = $transition->stepId !== null
            ? $this->findStep($targetWorkflow, $transition->stepId)
            : ($targetWorkflow->steps[0] ?? null);

        if ($targetStep !== null) {
            $dispatchState = $transition->state;
            assert($dispatchState instanceof ExecutionState);
            $this->queueDriver->dispatch(new ExecuteStepJob($targetStep, $dispatchState->toContext()), $transition->delaySeconds);
        }

        return true;
    }

    private function recordStepException(Step $step, WorkflowContext $context, SpanInterface $span, Throwable $exception): never
    {
        $executionId = $context->getExecutionId();
        $span->recordException($exception);
        $span->setStatus(false, $exception->getMessage());
        $category = match (true) {
            $exception instanceof PreflightFailureException => 'authoring',
            $exception instanceof SchemaValidationException => 'schema',
            default => 'execution',
        };
        $this->logger->error('Step execution failed with exception.', [
            'executionId' => $executionId,
            'stepId' => $step->stepId,
            'category' => $category,
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
        ]);
        $this->events->dispatch(new StepFailedEvent(
            $executionId,
            $context->getWorkflowId() ?? '',
            $step->stepId,
            $exception,
            $this->clock->now(),
            $category,
        ));
        $this->events->dispatch(new RunFailedEvent(
            $executionId,
            $context->getWorkflowId() ?? '',
            $exception,
            $this->clock->now(),
            $category,
        ));

        throw $exception;
    }

    private function findWorkflow(ArazzoDocument $document, ?string $workflowId): ?Workflow
    {
        return array_find($document->workflows, fn ($workflow) => $workflow->workflowId === $workflowId);
    }

    private function resolveExecutor(Step $step, ArazzoDocument $document): OperationExecutorPluginInterface
    {
        if ($this->executors === null) {
            throw new ExecutionException('No operation executor registry configured; register a protocol executor plugin.');
        }

        $executor = $this->executors->resolve($step, $document);
        if ($executor === null) {
            throw new LogicException("No OperationExecutorPluginInterface supports step '{$step->stepId}'.");
        }

        return $executor;
    }

    private function findStep(Workflow $workflow, string $stepId): ?Step
    {
        return array_find($workflow->steps, fn ($step) => $step->stepId === $stepId);
    }

    private function documentPreflightValidation(WorkflowContext $context): void
    {
        if ($this->preflight !== null && $context->getSteps() === []) {
            $preflightDoc = $this->definitions->find($context->getDefinitionId());
            if ($preflightDoc !== null) {
                $preflightResult = $this->preflight->validate($preflightDoc);
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
