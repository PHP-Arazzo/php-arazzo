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
