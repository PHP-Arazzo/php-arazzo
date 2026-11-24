<?php

declare(strict_types=1);

namespace Alama\Arazzo\Contracts\Spec;

use Alama\Arazzo\Contracts\Spec\Action\FailureAction;
use Alama\Arazzo\Contracts\Spec\Action\SuccessAction;

final readonly class Step
{
    public function __construct(
        public string $stepId,
        public ?string $description,
        public StepTarget $target,
        public StepFlow $flow,
        public StepIo $io,
    ) {}

    public function isHttpOperation(): bool
    {
        return $this->target->operationId !== null || $this->target->operationPath !== null;
    }

    public function isAsyncAction(): bool
    {
        return in_array($this->target->action, ['send', 'receive'], true);
    }

    public function hasAction(): bool
    {
        return $this->target->action !== null;
    }

    public function isReceiveAction(): bool
    {
        return $this->target->action === 'receive';
    }

    public function isSubWorkflow(): bool
    {
        return $this->target->workflowId !== null && !$this->isAsyncAction();
    }

    public function isInteraction(): bool
    {
        return $this->target->interaction !== null;
    }

    public function isActorHandoff(): bool
    {
        return $this->isInteraction()
            || ($this->isReceiveAction() && $this->target->channelPath !== null);
    }

    public function operationReference(): ?string
    {
        return $this->target->operationName
            ?? $this->target->operationId
            ?? $this->target->operationPath;
    }

    /**
     * @return array<SuccessAction|FailureAction|Reusable>
     */
    public function effectiveActions(bool $criteriaMet, Workflow $workflow): array
    {
        $actions = $criteriaMet ? $this->flow->onSuccess : $this->flow->onFailure;

        if ($actions !== []) {
            return $actions;
        }

        return $criteriaMet ? $workflow->successActions->all() : $workflow->failureActions->all();
    }

    /**
     * @return array<SuccessAction|FailureAction|Reusable>
     */
    public function allActions(Workflow $workflow): array
    {
        return [
            ...$this->flow->onSuccess,
            ...$this->flow->onFailure,
            ...$workflow->successActions->all(),
            ...$workflow->failureActions->all(),
        ];
    }

    /**
     * @return array<Parameter|Reusable>
     */
    public function parameters(): array
    {
        return $this->io->parameters;
    }

    /**
     * @return array<string, Expression|Selector|scalar|array<mixed>|null>
     */
    public function outputs(): array
    {
        return $this->io->outputs;
    }

    /**
     * @return list<SuccessCriterion>
     */
    public function successCriteria(): array
    {
        return $this->io->successCriteria;
    }

    public function requestBody(): ?RequestBody
    {
        return $this->io->requestBody;
    }

    public function hasOutputs(): bool
    {
        return $this->io->outputs !== [];
    }

    public function hasParameters(): bool
    {
        return $this->io->parameters !== [];
    }

    public function hasSuccessCriteria(): bool
    {
        return $this->io->successCriteria !== [];
    }

    public function hasRequestBody(): bool
    {
        return $this->io->requestBody !== null;
    }

    /**
     * @return list<string>
     */
    public function dependsOn(): array
    {
        return $this->flow->dependsOn;
    }
}
