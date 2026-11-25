<?php

declare(strict_types=1);

namespace Alama\Arazzo\Runner\Execution\Data;

use Alama\Arazzo\Contracts\Interfaces\QueueDriverInterface;
use Alama\Arazzo\Runner\Policy\ExecutionPolicy;
use Alama\Arazzo\Runner\Preflight\Preflight;
use Alama\Arazzo\Runner\Sync\WorkflowEngine;

/**
 * Control-flow capability: the collaborators an orchestrator needs to
 * decide and route execution (engine, queue, observability, policy, preflight gate).
 *
 * @internal stays out of the advertised contract; not part of the public API surface
 */
final readonly class RunControlFlow
{
    public function __construct(
        public WorkflowEngine $workflowEngine,
        public QueueDriverInterface $queueDriver,
        public ObservabilityContext $observability = new ObservabilityContext(),
        public ExecutionPolicy $policy = new ExecutionPolicy(),
        public ?Preflight $preflight = null,
    ) {}

    public function withWorkflowEngine(WorkflowEngine $workflowEngine): self
    {
        return new self($workflowEngine, $this->queueDriver, $this->observability, $this->policy, $this->preflight);
    }

    public function withQueueDriver(QueueDriverInterface $queueDriver): self
    {
        return new self($this->workflowEngine, $queueDriver, $this->observability, $this->policy, $this->preflight);
    }

    public function withObservability(ObservabilityContext $observability): self
    {
        return new self($this->workflowEngine, $this->queueDriver, $observability, $this->policy, $this->preflight);
    }

    public function withPolicy(ExecutionPolicy $policy): self
    {
        return new self($this->workflowEngine, $this->queueDriver, $this->observability, $policy, $this->preflight);
    }

    public function withPreflight(?Preflight $preflight): self
    {
        return new self($this->workflowEngine, $this->queueDriver, $this->observability, $this->policy, $preflight);
    }
}
