<?php

declare(strict_types=1);

namespace Alama\Arazzo\Contracts\State;

use Alama\Arazzo\Contracts\Policy\SourcePolicy;
use Alama\Arazzo\Contracts\Spec\Enum\StepStatus;
use Alama\Arazzo\Contracts\Spec\Interfaces\WorkflowContextInterface;
use Alama\Arazzo\Contracts\Spec\Workflow;

final readonly class WorkflowContext implements WorkflowContextInterface
{
    private string $executionId;

    /**
     * @param  array<string, mixed>  $inputs
     * @param  array<string, mixed>  $steps
     * @param  array<string, mixed>  $components
     * @param  array<string, array{inputs: array<string, mixed>, outputs: array<string, mixed>}>  $workflows
     */
    public function __construct(
        private string $definitionId,
        private array $inputs = [],
        private array $steps = [],
        private array $components = [],
        private ?string $workflowId = null,
        ?string $executionId = null,
        private array $workflows = [],
        private int $stepsSpent = 0,
        /** @var list<string> */
        private array $workflowCallStack = [],
        /** @var list<string> */
        private array $skippedSteps = [],
        public ?string $parentRunId = null,
        private ?SourcePolicy $activeSourcePolicy = null,
    ) {
        $this->executionId = $executionId ?? uniqid('run_', true);
    }

    public function getStepsSpent(): int
    {
        return $this->stepsSpent;
    }

    /** @return list<string> */
    public function getWorkflowCallStack(): array
    {
        return $this->workflowCallStack;
    }

    /**
     * Persistence payload: the canonical serialized shape of a run's
     * state, including budget/call-stack so queue resumes stay exact.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'definitionId' => $this->definitionId,
            'workflowId' => $this->workflowId,
            'steps' => $this->steps,
            'inputs' => $this->inputs,
            'components' => $this->components,
            'stepsSpent' => $this->stepsSpent,
            'workflowCallStack' => $this->workflowCallStack,
            'workflows' => $this->workflows,
            'skippedSteps' => $this->skippedSteps,
        ];
    }

    /**
     * Hydrates a context from a persisted payload (CorrelationResumer).
     *
     * @param  array<string, mixed>  $persisted
     */
    public static function fromPersisted(array $persisted, string $executionId): self
    {
        return WorkflowContextHydrator::fromPersisted($persisted, $executionId);
    }

    /**
     * Reconciles an incoming job context with the persisted payload:
     * persisted steps win on conflict (they are newer), and the stored
     * budget/call-stack is authoritative.
     *
     * @param  array<string, mixed>  $persisted
     */
    public static function reconciled(self $incoming, array $persisted, string $executionId): self
    {
        return WorkflowContextHydrator::reconciled($incoming, $persisted, $executionId);
    }

    /**
     * @param  list<string>  $workflowCallStack
     */
    public function withBudget(int $stepsSpent, array $workflowCallStack): self
    {
        return new self($this->definitionId, $this->inputs, $this->steps, $this->components, $this->workflowId, $this->executionId, workflows: $this->workflows, stepsSpent: $stepsSpent, workflowCallStack: $workflowCallStack, skippedSteps: $this->skippedSteps, parentRunId: $this->parentRunId);
    }

    /**
     * @param  array<string, mixed>  $inputs
     */
    public static function forChildInvocation(
        WorkflowContext $parent,
        Workflow $target,
        array $inputs,
    ): self {
        // Children SHARE the parent's step budget and call stack: nested
        // attempts consume from the same pool and depth guards still apply.
        $callStack = $parent->getWorkflowCallStack();
        $callStack[] = $target->workflowId;

        $child = new self(
            definitionId: $parent->getDefinitionId(),
            inputs: $inputs,
            steps: [],
            components: $parent->getComponents(),
            workflowId: $target->workflowId,
            executionId: uniqid('run_', true),
            workflows: $parent->getWorkflows(),
            stepsSpent: $parent->getStepsSpent(),
            workflowCallStack: $callStack,
            parentRunId: $parent->getExecutionId(),
        );

        return $child;
    }

    public function getDefinitionId(): string
    {
        return $this->definitionId;
    }

    /**
     * @return array<string, mixed>
     */
    public function getComponents(): array
    {
        return $this->components;
    }

    public function getExecutionId(): string
    {
        return $this->executionId;
    }

    public function getWorkflowId(): ?string
    {
        return $this->workflowId;
    }

    /**
     * @return array<string, mixed>
     */
    public function getInputs(): array
    {
        return $this->inputs;
    }

    /**
     * @return array<string, array{inputs: array<string, mixed>, outputs: array<string, mixed>}>
     */
    public function getWorkflows(): array
    {
        return $this->workflows;
    }

    /**
     * @param  array{inputs?: array<string, mixed>, outputs?: array<string, mixed>}  $data
     */
    public function withWorkflowData(string $workflowId, array $data): self
    {
        $newWorkflows = $this->workflows;
        $existing = $newWorkflows[$workflowId] ?? ['inputs' => [], 'outputs' => []];
        $newWorkflows[$workflowId] = [
            'inputs' => $data['inputs'] ?? $existing['inputs'],
            'outputs' => $data['outputs'] ?? $existing['outputs'],
        ];

        return new self(
            definitionId: $this->definitionId,
            inputs: $this->inputs,
            steps: $this->steps,
            components: $this->components,
            workflowId: $this->workflowId,
            executionId: $this->executionId,
            workflows: $newWorkflows,
            stepsSpent: $this->stepsSpent,
            workflowCallStack: $this->workflowCallStack,
            skippedSteps: $this->skippedSteps,
            parentRunId: $this->parentRunId,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function getSteps(): array
    {
        return $this->steps;
    }

    public function withWorkflowId(string $workflowId): self
    {
        return new self($this->definitionId, $this->inputs, $this->steps, $this->components, $workflowId, $this->executionId, workflows: $this->workflows, stepsSpent: $this->stepsSpent, workflowCallStack: $this->workflowCallStack, skippedSteps: $this->skippedSteps);
    }

    public function withExecutionId(string $executionId): self
    {
        return new self($this->definitionId, $this->inputs, $this->steps, $this->components, $this->workflowId, $executionId, workflows: $this->workflows, stepsSpent: $this->stepsSpent, workflowCallStack: $this->workflowCallStack, skippedSteps: $this->skippedSteps);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    public function withStepResult(string $stepId, array $result): self
    {
        $newSteps = $this->steps;
        $newSteps[$stepId] = $result;

        return new self($this->definitionId, $this->inputs, $newSteps, $this->components, $this->workflowId, $this->executionId, workflows: $this->workflows, stepsSpent: $this->stepsSpent, workflowCallStack: $this->workflowCallStack, skippedSteps: $this->skippedSteps);
    }

    /**
     * @param  array<string, mixed>  $request
     */
    public function withStepRequest(string $stepId, array $request): self
    {
        $newSteps = $this->steps;
        /** @var array<string, mixed> $record */
        $record = is_array($newSteps[$stepId] ?? null) ? $newSteps[$stepId] : [];
        $record['request'] = $request;
        $newSteps[$stepId] = $record;

        return new self($this->definitionId, $this->inputs, $newSteps, $this->components, $this->workflowId, $this->executionId, workflows: $this->workflows, stepsSpent: $this->stepsSpent, workflowCallStack: $this->workflowCallStack, skippedSteps: $this->skippedSteps);
    }

    /**
     * @param  array<string, mixed>  $response
     */
    public function withStepResponse(string $stepId, array $response): self
    {
        $newSteps = $this->steps;
        /** @var array<string, mixed> $record */
        $record = is_array($newSteps[$stepId] ?? null) ? $newSteps[$stepId] : [];
        $record['response'] = $response;
        $newSteps[$stepId] = $record;

        return new self($this->definitionId, $this->inputs, $newSteps, $this->components, $this->workflowId, $this->executionId, workflows: $this->workflows, stepsSpent: $this->stepsSpent, workflowCallStack: $this->workflowCallStack, skippedSteps: $this->skippedSteps);
    }

    public function withStepOutput(string $stepId, string $key, mixed $value): self
    {
        $newSteps = $this->steps;
        /** @var array<string, mixed> $record */
        $record = is_array($newSteps[$stepId] ?? null) ? $newSteps[$stepId] : [];
        /** @var array<string, mixed> $outputs */
        $outputs = is_array($record['outputs'] ?? null) ? $record['outputs'] : [];
        $outputs[$key] = $value;
        $record['outputs'] = $outputs;
        $newSteps[$stepId] = $record;

        return new self($this->definitionId, $this->inputs, $newSteps, $this->components, $this->workflowId, $this->executionId, workflows: $this->workflows, stepsSpent: $this->stepsSpent, workflowCallStack: $this->workflowCallStack, skippedSteps: $this->skippedSteps);
    }

    public function withInput(string $name, mixed $value): self
    {
        $inputs = $this->inputs;
        $inputs[$name] = $value;

        return new self($this->definitionId, $inputs, $this->steps, $this->components, $this->workflowId, $this->executionId, workflows: $this->workflows, stepsSpent: $this->stepsSpent, workflowCallStack: $this->workflowCallStack, skippedSteps: $this->skippedSteps);
    }

    /**
     * @param  array<string, mixed>  $inputs
     */
    public function withInputs(array $inputs): self
    {
        return new self($this->definitionId, $inputs, $this->steps, $this->components, $this->workflowId, $this->executionId, workflows: $this->workflows, stepsSpent: $this->stepsSpent, workflowCallStack: $this->workflowCallStack, skippedSteps: $this->skippedSteps);
    }

    /**
     * @param  array<string, mixed>  $inputs
     */
    public function withStepInputs(string $stepId, array $inputs): self
    {
        $newSteps = $this->steps;
        $existing = $newSteps[$stepId] ?? [];
        $existing = is_array($existing) ? $existing : [];
        $existing['inputs'] = $inputs;
        $newSteps[$stepId] = $existing;

        return new self($this->definitionId, $this->inputs, $newSteps, $this->components, $this->workflowId, $this->executionId, workflows: $this->workflows, stepsSpent: $this->stepsSpent, workflowCallStack: $this->workflowCallStack, skippedSteps: $this->skippedSteps);
    }

    public function getStepStatus(string $stepId): ?StepStatus
    {
        $record = $this->steps[$stepId] ?? null;
        $status = is_array($record) ? ($record['status'] ?? null) : null;

        if ($status instanceof StepStatus) {
            return $status;
        }

        return is_string($status) ? StepStatus::tryFrom($status) : null;
    }

    public function withStepStatus(string $stepId, StepStatus $status): self
    {
        $newSteps = $this->steps;
        /** @var array<string, mixed> $record */
        $record = is_array($newSteps[$stepId] ?? null) ? $newSteps[$stepId] : [];
        $record['status'] = $status;
        $newSteps[$stepId] = $record;

        return new self($this->definitionId, $this->inputs, $newSteps, $this->components, $this->workflowId, $this->executionId, workflows: $this->workflows, stepsSpent: $this->stepsSpent, workflowCallStack: $this->workflowCallStack, skippedSteps: $this->skippedSteps);
    }

    public function getStepAttempts(string $stepId): int
    {
        $record = $this->steps[$stepId] ?? null;
        $attempts = is_array($record) ? ($record['attempts'] ?? null) : null;

        return is_int($attempts) ? $attempts : 0;
    }

    public function withStepAttemptIncremented(string $stepId): self
    {
        $newSteps = $this->steps;
        /** @var array<string, mixed> $record */
        $record = is_array($newSteps[$stepId] ?? null) ? $newSteps[$stepId] : [];
        $attempts = is_int($record['attempts'] ?? null) ? $record['attempts'] : 0;
        $record['attempts'] = $attempts + 1;
        $newSteps[$stepId] = $record;

        return new self($this->definitionId, $this->inputs, $newSteps, $this->components, $this->workflowId, $this->executionId, workflows: $this->workflows, stepsSpent: $this->stepsSpent, workflowCallStack: $this->workflowCallStack, skippedSteps: $this->skippedSteps);
    }

    /**
     * @return array<string, mixed>
     */
    public function rootScope(): array
    {
        return [
            'inputs' => $this->inputs,
            'steps' => $this->steps,
            'components' => $this->components,
        ];
    }

    /**
     * @return list<string>
     */
    public function getSkippedSteps(): array
    {
        return $this->skippedSteps;
    }

    /**
     * @param  list<string>  $skippedSteps
     */
    public function withSkippedSteps(array $skippedSteps): self
    {
        return new self(
            $this->definitionId,
            $this->inputs,
            $this->steps,
            $this->components,
            $this->workflowId,
            $this->executionId,
            workflows: $this->workflows,
            stepsSpent: $this->stepsSpent,
            workflowCallStack: $this->workflowCallStack,
            parentRunId: $this->parentRunId,
            skippedSteps: $skippedSteps,
        );
    }

    /**
     * Transient per-step source policy resolved by the runner for the active step.
     * Deliberately excluded from {@see self::toArray()} so source credentials are
     * never serialized into persisted hot state.
     */
    public function getActiveSourcePolicy(): ?SourcePolicy
    {
        return $this->activeSourcePolicy;
    }

    public function withActiveSourcePolicy(?SourcePolicy $policy): self
    {
        return new self(
            $this->definitionId,
            $this->inputs,
            $this->steps,
            $this->components,
            $this->workflowId,
            $this->executionId,
            workflows: $this->workflows,
            stepsSpent: $this->stepsSpent,
            workflowCallStack: $this->workflowCallStack,
            skippedSteps: $this->skippedSteps,
            parentRunId: $this->parentRunId,
            activeSourcePolicy: $policy,
        );
    }
}
