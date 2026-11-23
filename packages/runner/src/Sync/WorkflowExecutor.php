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
use Alama\Arazzo\Contracts\Validation\ValidationResult;
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
use Alama\Arazzo\Runner\Exceptions\PreflightFailureException;
use Alama\Arazzo\Runner\Exceptions\UnknownWorkflowException;
use Alama\Arazzo\Runner\Execution\Data\ObservabilityContext;
use Alama\Arazzo\Runner\Execution\Enum\TransitionType;
use Alama\Arazzo\Runner\Preflight\Preflight;
use LogicException;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
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

    public function __construct(
        private StepExecutor $stepExecutor,
        private WorkflowEngine $workflowEngine,
        EventDispatcherInterface|ObservabilityContext|null $events = null,
        private ?Preflight $preflight = null,
        private ?DocumentInterface $document = null,
    ) {
        $observability = $events instanceof ObservabilityContext
            ? $events
            : new ObservabilityContext(events: $events);

        $this->events = $observability->events;
        $this->logger = $observability->logger;
        $this->tracer = $observability->tracer;
        $this->clock = $observability->clock;
    }

    /**
     * @param  array<string, mixed>  $inputs
     */
    public function execute(Workflow $workflow, ArazzoDocument $document, array $inputs, ?WorkflowContext $context = null): ExecutionResult
    {
        // Preflight runs before the first side effect (no events, no state).
        $inputs = $this->runPreflight($workflow, $document, $inputs);

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
     * @param  array<string, mixed>  $inputs
     * @return array<string, mixed>
     */
    private function runPreflight(Workflow $workflow, ArazzoDocument $document, array $inputs): array
    {
        $errors = [];
        $warnings = [];

        $inputsSchema = $workflow->inputs;
        if (is_array($inputsSchema) && $inputsSchema !== [] && $this->document !== null) {
            $inputResult = $this->document->validateInputs($document, $workflow->workflowId, $inputs);
            if ($inputResult->errors !== []) {
                $errors = [...$errors, ...$inputResult->errors];
                $warnings = [...$warnings, ...$inputResult->warnings];
            } elseif ($inputResult->inputs !== []) {
                $inputs = $inputResult->inputs;
            }
        }

        if ($this->preflight !== null) {
            $preflightResult = $this->preflight->validate($document);
            $errors = [...$errors, ...$preflightResult->errors];
            $warnings = [...$warnings, ...$preflightResult->warnings];
        }

        if ($errors !== []) {
            $result = new ValidationResult($document, $errors, $warnings);
            throw new PreflightFailureException(
                'Preflight validation failed with '.count($result->errors).' error(s).',
                $result,
            );
        }

        return $inputs;
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

            $dependency = $this->workflow($document, $dependencyId);
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
                    new RuntimeException("Dependency '{$dependencyId}' failed."),
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
        $state = $this->carryWorkflowData($state, $context);
        $currentWorkflow = $workflow;
        $stepId = $this->firstStep($workflow);
        $results = [];
        try {
            while ($stepId !== null) {
                $step = $this->step($currentWorkflow, $stepId);
                if ($step === null) {
                    throw new LogicException("Unknown step '{$stepId}' in workflow '{$currentWorkflow->workflowId}'.");
                }
                $attempt = $state->attemptFor($stepId) + 1;
                $this->events->dispatch(new StepStartedEvent($executionId, $currentWorkflow->workflowId, $stepId, $attempt, $this->clock->now()));
                [$stepContext, $success] = $this->executeTracedStep($step, $currentWorkflow, $state, $document, $executionId);
                $raw = $stepContext->getSteps()[$stepId] ?? [];
                /** @var array<string, mixed> $raw */
                $state = $state->withStepResult($stepId, $raw);
                $result = new StepResult(
                    $stepId,
                    $success,
                    is_array($raw['outputs'] ?? null) ? $raw['outputs'] : [],
                    $success ? null : new RuntimeException("Step '{$stepId}' failed"),
                );
                $results[$stepId] = $result;
                $transition = $this->workflowEngine->transition($document, $currentWorkflow, $step, $state, $success);
                $engineState = $transition->state;
                assert($engineState instanceof ExecutionState);
                $state = $engineState;
                if ($transition->type === TransitionType::Retry) {
                    $this->events->dispatch(new StepRetriedEvent($executionId, $currentWorkflow->workflowId, $stepId, $state->attemptFor($stepId), null, $this->clock->now()));
                }
                if (!$success) {
                    $this->events->dispatch(new StepFailedEvent(
                        $executionId,
                        $currentWorkflow->workflowId,
                        $stepId,
                        new RuntimeException("Step '{$stepId}' failed"),
                        $this->clock->now(),
                        is_string($raw['failureCategory'] ?? null) ? $raw['failureCategory'] : 'criteria',
                    ));
                } else {
                    $this->events->dispatch(new StepExecutedEvent($executionId, $currentWorkflow->workflowId, $stepId, (int) (is_scalar($raw['statusCode'] ?? null) ? $raw['statusCode'] : 0), $result->outputs, $success, $this->clock->now()));
                }
                if ($transition->isTerminal()) {
                    if ($transition->status === 'failed') {
                        $this->logger->warning('Workflow ended in failure.', [
                            'executionId' => $executionId,
                            'workflowId' => $currentWorkflow->workflowId,
                            'stepId' => $stepId,
                        ]);
                        $this->events->dispatch(new RunFailedEvent($executionId, $currentWorkflow->workflowId, new RuntimeException("Workflow '{$currentWorkflow->workflowId}' failed at step '{$stepId}'."), $this->clock->now(), 'criteria'));

                        return new ExecutionResult($currentWorkflow->workflowId, ExecutionStatus::Failed, [], $results, $state->stepsSpent, $state->workflowCallStack);
                    }
                    $outputs = $this->workflowEngine->evaluateWorkflowOutputs($currentWorkflow, $state);
                    $this->events->dispatch(new RunCompletedEvent($executionId, $currentWorkflow->workflowId, $outputs, $this->clock->now()));

                    return new ExecutionResult($currentWorkflow->workflowId, ExecutionStatus::Succeeded, $outputs, $results, $state->stepsSpent, $state->workflowCallStack);
                }
                if ($transition->workflowId !== null && $transition->workflowId !== $currentWorkflow->workflowId) {
                    $currentWorkflow = $this->workflow($document, $transition->workflowId);
                }
                $stepId = $transition->stepId ?? $this->firstStep($currentWorkflow);
            }
        } catch (Throwable $t) {
            $category = match (true) {
                $t instanceof PreflightFailureException => 'authoring',
                $t instanceof SchemaValidationException => 'schema',
                default => 'execution',
            };
            $this->logger->error('Workflow execution threw an exception.', [
                'executionId' => $executionId,
                'workflowId' => $currentWorkflow->workflowId,
                'category' => $category,
                'exception' => $t::class,
                'message' => $t->getMessage(),
            ]);
            $this->events->dispatch(new RunFailedEvent($executionId, $currentWorkflow->workflowId, $t, $this->clock->now(), $category));
            throw $t;
        }

        return new ExecutionResult($currentWorkflow->workflowId, ExecutionStatus::Succeeded, $state->outputs, $results, $state->stepsSpent, $state->workflowCallStack);
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

    private function firstStep(Workflow $workflow): ?string
    {
        return $workflow->steps[0]->stepId ?? null;
    }

    private function step(Workflow $workflow, string $id): ?Step
    {
        foreach ($workflow->steps as $step) {
            if ($step->stepId === $id) {
                return $step;
            }
        }

        return null;
    }

    private function workflow(ArazzoDocument $document, string $id): Workflow
    {
        foreach ($document->workflows as $workflow) {
            if ($workflow->workflowId === $id) {
                return $workflow;
            }
        }

        throw UnknownWorkflowException::forId($id);
    }
}
