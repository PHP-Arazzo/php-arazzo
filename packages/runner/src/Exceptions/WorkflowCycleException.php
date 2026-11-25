<?php

declare(strict_types=1);

namespace Alama\Arazzo\Runner\Exceptions;

final class WorkflowCycleException extends RunnerException
{
    public static function detected(string $workflowId): self
    {
        return new self(
            "Workflow '{$workflowId}' is already on the execution call stack.",
            '/workflows',
            'runner.workflow_cycle',
        );
    }
}
