<?php

declare(strict_types=1);

namespace Alama\Arazzo\Runner\Exceptions;

use LogicException;

final class UnsupportedStepException extends LogicException
{
    public static function noSupportingExecutor(string $stepId): self
    {
        return new self("No OperationExecutorPluginInterface supports step '{$stepId}'.");
    }

    public static function unknownWorkflow(string $workflowId): self
    {
        return new self("Unknown workflow '{$workflowId}'.");
    }
}
