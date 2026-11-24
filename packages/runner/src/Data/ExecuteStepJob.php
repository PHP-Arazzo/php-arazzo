<?php

declare(strict_types=1);

namespace Alama\Arazzo\Runner\Data;

use Alama\Arazzo\Contracts\Spec\Step;
use Alama\Arazzo\Contracts\State\WorkflowContext;
use Alama\Arazzo\Runner\AsyncRunnerInterface;

/**
 * Queued step execution job consumed by {@see AsyncRunnerInterface::handleStep()}.
 */
final readonly class ExecuteStepJob
{
    public function __construct(
        public Step $step,
        public WorkflowContext $context,
    ) {}

    public function executionId(): string
    {
        return $this->context->getExecutionId();
    }

    public function stepId(): string
    {
        return $this->step->stepId;
    }
}
