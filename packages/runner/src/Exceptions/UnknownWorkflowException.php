<?php

declare(strict_types=1);

namespace Alama\Arazzo\Runner\Exceptions;

final class UnknownWorkflowException extends RunnerException
{
    public static function forId(string $workflowId): self
    {
        return new self(
            sprintf("Unknown workflow '%s'.", $workflowId),
            '/workflows',
            'runner.unknown_workflow',
        );
    }
}
