<?php

declare(strict_types=1);

namespace Alama\Arazzo\Contracts\Spec\Interfaces;

use Alama\Arazzo\Contracts\Spec\Enum\StepStatus;

interface WorkflowContextInterface
{
    /** @return array<string, mixed> */
    public function getInputs(): array;

    /** @return array<string, mixed> */
    public function getSteps(): array;

    /** @return array<string, mixed> */
    public function getComponents(): array;

    /** @return array<string, array{inputs: array<string, mixed>, outputs: array<string, mixed>}> */
    public function getWorkflows(): array;

    public function getStepStatus(string $stepId): ?StepStatus;

    public function getWorkflowId(): ?string;

    public function hasStep(string $stepId): bool;

    public function hasExecutedSteps(): bool;

    /** @return array<mixed, mixed>|null */
    public function getStepRecord(string $stepId): ?array;

    /** @return array<string, mixed> */
    public function getStepOutputs(string $stepId): array;

    /** @return array<mixed, mixed>|null */
    public function getStepResponse(string $stepId): ?array;

    /** @return array<mixed, mixed>|null */
    public function getStepRequest(string $stepId): ?array;

    /** @return array<mixed, mixed> */
    public function getStepInputs(string $stepId): array;

    public function getStepStatusCode(string $stepId): ?int;

    public function getInput(string $name): mixed;

    public function hasInput(string $name): bool;
}
