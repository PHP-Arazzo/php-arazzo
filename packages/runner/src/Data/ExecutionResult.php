<?php

declare(strict_types=1);

namespace Alama\Arazzo\Runner\Data;

use Alama\Arazzo\Contracts\Spec\Enum\ExecutionStatus;

/**
 * Immutable typed outcome of a synchronous workflow execution.
 */
final readonly class ExecutionResult
{
    /**
     * @param  array<string, mixed>  $outputs
     * @param  array<string, StepResult>  $stepResults
     * @param  list<string>  $workflowCallStack
     */
    public function __construct(
        public string $workflowId,
        public ExecutionStatus $status,
        public array $outputs = [],
        public array $stepResults = [],
        public int $stepsSpent = 0,
        public array $workflowCallStack = [],
    ) {}

    public function isSucceeded(): bool
    {
        return $this->status === ExecutionStatus::Succeeded;
    }

    public function isFailed(): bool
    {
        return $this->status === ExecutionStatus::Failed;
    }

    public function stepResult(string $stepId): ?StepResult
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

    public function failedStep(): ?StepResult
    {
        foreach ($this->stepResults as $result) {
            if ($result->isFailed()) {
                return $result;
            }
        }

        return null;
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
     *     steps: array<string, array{stepId: string, success: bool, outputs: array<array-key, mixed>, error: ?string}>
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
            'steps' => $steps,
        ];
    }
}
