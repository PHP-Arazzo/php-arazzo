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
}
