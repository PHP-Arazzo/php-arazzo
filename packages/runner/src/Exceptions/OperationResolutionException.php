<?php

declare(strict_types=1);

namespace Alama\Arazzo\Runner\Exceptions;

final class OperationResolutionException extends RunnerException
{
    public static function unsupportedStep(string $stepId): self
    {
        return new self(
            "No operation resolver supports step '{$stepId}'.",
            '/workflows',
            'runner.unsupported_operation_step',
        );
    }
}
