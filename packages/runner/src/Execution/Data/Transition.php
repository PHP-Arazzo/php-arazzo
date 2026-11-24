<?php

declare(strict_types=1);

namespace Alama\Arazzo\Runner\Execution\Data;

use Alama\Arazzo\Contracts\Spec\Workflow;
use Alama\Arazzo\Contracts\State\ExecutionState;
use Alama\Arazzo\Runner\Execution\Enum\TransitionType;
use Alama\Arazzo\Runtime\State\Data\ExecutionContext;

/**
 * @internal stays out of the advertised contract; not part of the public API surface
 */
final readonly class Transition
{
    private function __construct(
        public TransitionType $type,
        public ExecutionState|ExecutionContext $state,
        public ?string $stepId = null,
        public ?string $workflowId = null,
        public int $delaySeconds = 0,
        public ?string $status = null,
    ) {}

    public static function next(ExecutionState|ExecutionContext $state, ?string $stepId): self
    {
        return new self(TransitionType::Next, $state, $stepId);
    }

    public static function retry(ExecutionState|ExecutionContext $state, string $stepId, int $delaySeconds = 0, ?string $workflowId = null): self
    {
        return new self(TransitionType::Retry, $state, $stepId, $workflowId, $delaySeconds);
    }

    public static function goto(ExecutionState|ExecutionContext $state, ?string $stepId, string $workflowId): self
    {
        return new self(TransitionType::Goto, $state, $stepId, $workflowId);
    }

    public static function end(ExecutionState|ExecutionContext $state, string $status): self
    {
        return new self(TransitionType::End, $state->withStatus($status), null, null, 0, $status);
    }

    public static function invoke(ExecutionState|ExecutionContext $state, string $workflowId): self
    {
        return new self(TransitionType::Invoke, $state->enterWorkflow($workflowId), null, $workflowId);
    }

    public static function suspend(ExecutionState|ExecutionContext $state): self
    {
        return new self(TransitionType::Suspend, $state->withStatus('suspended'));
    }

    public function isTerminal(): bool
    {
        return $this->type === TransitionType::End;
    }

    public function isNext(): bool
    {
        return $this->type === TransitionType::Next;
    }

    public function isRetry(): bool
    {
        return $this->type === TransitionType::Retry;
    }

    public function isGoto(): bool
    {
        return $this->type === TransitionType::Goto;
    }

    public function isInvoke(): bool
    {
        return $this->type === TransitionType::Invoke;
    }

    public function isSuspend(): bool
    {
        return $this->type === TransitionType::Suspend;
    }

    public function isSucceeded(): bool
    {
        return $this->status === 'succeeded';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    public function targetWorkflowId(Workflow $current): string
    {
        return $this->workflowId ?? $current->workflowId;
    }
}
