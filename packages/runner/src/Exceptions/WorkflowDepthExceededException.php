<?php

declare(strict_types=1);

namespace Alama\Arazzo\Runner\Exceptions;

final class WorkflowDepthExceededException extends RunnerException
{
    public static function exceeded(int $maxDepth): self
    {
        return new self(
            "Execution exceeded workflow depth {$maxDepth}.",
            '/workflows',
            'runner.workflow_depth_exceeded',
        );
    }
}
