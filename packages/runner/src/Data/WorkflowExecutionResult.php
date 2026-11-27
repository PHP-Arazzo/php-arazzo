<?php

declare(strict_types=1);

namespace Alama\Arazzo\Runner\Data;

use Alama\Arazzo\Contracts\Spec\Enum\ExecutionStatus;

/**
 * Immutable typed outcome of a synchronous workflow execution.
 */
final readonly class WorkflowExecutionResult
{
    /**
     * @param  array<string, mixed>  $outputs
     * @param  array<string, StepExecutionResult>  $stepResults
     * @param  list<string>  $workflowCallStack
     * @param  list<string>  $sequentialFallbacks
     */
    public function __construct(
        public string $workflowId,
        public ExecutionStatus $status,
        public array $outputs = [],
        public array $stepResults = [],
        public int $stepsSpent = 0,
        public array $workflowCallStack = [],
        public array $sequentialFallbacks = [],
    ) {}

    public function isSucceeded(): bool
    {
        return $this->status === ExecutionStatus::Succeeded;
    }

    public function isFailed(): bool
    {
        return $this->status === ExecutionStatus::Failed;
    }

    public function stepResult(string $stepId): ?StepExecutionResult
    {
        return $this->stepResults[$stepId] ?? null;
    }

    public function hasStepResult(string $stepId): bool
    {
        return isset($this->stepResults[$stepId]);
    }

    public function output(string $name): mixed
    {
        return $this->outputs[$name] ?? null;
    }

    public function hasOutput(string $name): bool
    {
        return array_key_exists($name, $this->outputs);
    }

    public function failedStep(): ?StepExecutionResult
    {
        foreach ($this->stepResults as $result) {
            if ($result->isFailed()) {
                return $result;
            }
        }

        return null;
    }

    public function targetStepResult(): ?StepExecutionResult
    {
        foreach ($this->stepResults as $stepResult) {
            if ($stepResult->isTarget()) {
                return $stepResult;
            }
        }

        return null;
    }

    /**
     * @return array<string, StepExecutionResult>
     */
    public function dependencyStepResults(): array
    {
        return array_filter(
            $this->stepResults,
            static fn (StepExecutionResult $result): bool => $result->isDependency(),
        );
    }

    public function hasSequentialFallbacks(): bool
    {
        return $this->sequentialFallbacks !== [];
    }

    /**
     * @return list<string>
     */
    public function executedStepIds(): array
    {
        return array_keys($this->stepResults);
    }

    /**
     * @return array{
     *     workflowId: string,
     *     status: string,
     *     outputs: array<string, mixed>,
     *     stepsSpent: int,
     *     workflowCallStack: list<string>,
     *     sequentialFallbacks: list<string>,
     *     steps: array<string, array{
     *         stepId: string,
     *         success: bool,
     *         outputs: array<array-key, mixed>,
     *         error: ?string,
     *         role: string,
     *         dependency: bool,
     *         target: bool
     *     }>
     * }
     */
    public function toArray(): array
    {
        $steps = [];
        foreach ($this->stepResults as $stepId => $stepResult) {
            $steps[$stepId] = $stepResult->toArray();
        }

        return [
            'workflowId' => $this->workflowId,
            'status' => $this->status->value,
            'outputs' => $this->outputs,
            'stepsSpent' => $this->stepsSpent,
            'workflowCallStack' => $this->workflowCallStack,
            'sequentialFallbacks' => $this->sequentialFallbacks,
            'steps' => $steps,
        ];
    }
}
