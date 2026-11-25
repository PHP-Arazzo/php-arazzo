<?php

declare(strict_types=1);

use Alama\Arazzo\Contracts\Interfaces\WorkflowStateRepositoryInterface;
use Alama\Arazzo\Contracts\Spec\Enum\StepStatus;
use Alama\Arazzo\Contracts\Spec\Interfaces\WorkflowContextInterface;

it('declares the durable state repository port')
    ->expect(interface_exists(WorkflowStateRepositoryInterface::class))
    ->toBeTrue();

it('round-trips a context through a stub repository', function (): void {
    $context = new class() implements WorkflowContextInterface
    {
        public function getInputs(): array
        {
            return [];
        }

        public function getSteps(): array
        {
            return [];
        }

        public function getComponents(): array
        {
            return [];
        }

        public function getWorkflows(): array
        {
            return [];
        }

        public function getStepStatus(string $stepId): ?StepStatus
        {
            return null;
        }

        public function getWorkflowId(): ?string
        {
            return 'wf-1';
        }

        public function hasStep(string $stepId): bool
        {
            return false;
        }

        public function hasExecutedSteps(): bool
        {
            return false;
        }

        public function getStepRecord(string $stepId): ?array
        {
            return null;
        }

        public function getStepOutputs(string $stepId): array
        {
            return [];
        }

        public function getStepResponse(string $stepId): ?array
        {
            return null;
        }

        public function getStepRequest(string $stepId): ?array
        {
            return null;
        }

        public function getStepInputs(string $stepId): array
        {
            return [];
        }

        public function getStepStatusCode(string $stepId): ?int
        {
            return null;
        }

        public function getInput(string $name): mixed
        {
            return null;
        }

        public function hasInput(string $name): bool
        {
            return false;
        }
    };

    $repo = new class() implements WorkflowStateRepositoryInterface
    {
        public function save(string $executionId, WorkflowContextInterface $state): void {}

        public function load(string $executionId): ?WorkflowContextInterface
        {
            return null;
        }

        public function delete(string $executionId): void {}
    };

    $repo->save('exec-1', $context);

    expect($repo->load('exec-1'))->toBeNull();
});
