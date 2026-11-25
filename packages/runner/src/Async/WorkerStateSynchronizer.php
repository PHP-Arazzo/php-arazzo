<?php

declare(strict_types=1);

namespace Alama\Arazzo\Runner\Async;

use Alama\Arazzo\Contracts\Spec\Step;
use Alama\Arazzo\Contracts\Spec\StepExecutionOutcome;
use Alama\Arazzo\Contracts\Spec\Workflow;
use Alama\Arazzo\Contracts\State\ExecutionState;
use Alama\Arazzo\Contracts\State\WorkflowContext;
use Alama\Arazzo\Runner\ExecutionStoreInterface;
use Alama\Arazzo\Runner\Policy\ExecutionPolicy;

/**
 * Reconciles persisted async step state into canonical {@see ExecutionState}
 * snapshots for {@see StepExecutionWorker}.
 *
 * @internal stays out of the advertised contract; not part of the public API surface
 */
final readonly class WorkerStateSynchronizer
{
    public function __construct(
        private ExecutionStoreInterface $store,
    ) {}

    public function reconcile(WorkflowContext $context, string $executionId): WorkflowContext
    {
        $persisted = $this->store->loadState($executionId);

        return $persisted === null
            ? $context
            : WorkflowContext::reconciled($context, $persisted, $executionId);
    }

    public function recordStepOutcome(
        WorkflowContext $context,
        Step $step,
        StepExecutionOutcome $outcome,
        int $attempt,
    ): WorkflowContext {
        return $context->withStepResult($step->stepId, [
            'statusCode' => $outcome->statusCode,
            'request' => $outcome->request ?? [],
            'response' => [
                'statusCode' => $outcome->statusCode,
                'headers' => $outcome->responseHeaders,
                'body' => $outcome->responseBody,
            ],
            'rawBody' => $outcome->rawBody,
            'contentType' => $outcome->contentType,
            'failureCategory' => $outcome->failureCategory,
            'outputs' => $outcome->outputs,
            'inputs' => $outcome->inputs,
            'attempts' => $attempt,
        ]);
    }

    public function hydrateState(
        WorkflowContext $contextWithResult,
        Workflow $workflow,
        Step $step,
        ExecutionPolicy $policy,
    ): ExecutionState {
        $executionId = $contextWithResult->getExecutionId();
        $persisted = $this->store->loadState($executionId);

        $state = $persisted !== null && isset($persisted['executionId'])
            ? ExecutionState::fromArray($persisted)
            : ExecutionState::start(
                $executionId,
                $contextWithResult->getDefinitionId(),
                $workflow->workflowId,
                $contextWithResult->getInputs(),
                maxSteps: $policy->maxSteps(),
                maxWorkflowDepth: $policy->maxWorkflowDepth(),
                components: $contextWithResult->getComponents(),
            );

        foreach ($contextWithResult->getSteps() as $completedStepId => $completedResult) {
            $state = $state->withStepResult($completedStepId, self::stepRecord($completedResult));
        }

        $state = $state->restoreBudget(
            $contextWithResult->getStepsSpent(),
            $contextWithResult->getWorkflowCallStack() ?: [$workflow->workflowId],
        );

        foreach ($this->attemptsFrom($contextWithResult) as $attemptedStepId => $attempts) {
            while ($state->attemptFor($attemptedStepId) < $attempts - 1) {
                $state = $state->withStepAttempt($attemptedStepId);
            }
        }

        $record = self::stepRecord($contextWithResult->getSteps()[$step->stepId] ?? null);

        return $state->withStepResult($step->stepId, $record);
    }

    /**
     * @return array<string, int>
     */
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
}
