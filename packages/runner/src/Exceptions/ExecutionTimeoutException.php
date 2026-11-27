<?php

declare(strict_types=1);

namespace Alama\Arazzo\Runner\Exceptions;

use Alama\Arazzo\Runner\Policy\ExecutionBudgets;

/**
 * Thrown when cumulative workflow wall-clock execution time exceeds
 * {@see ExecutionBudgets::$executionTimeout}.
 */
final class ExecutionTimeoutException extends RunnerException
{
    public const string ERROR_CODE = 'transport.execution_timeout';

    public static function exceeded(string $executionId, float $timeoutSeconds, float $elapsedSeconds): self
    {
        $elapsed = round($elapsedSeconds, 3);

        return new self(
            "Execution '{$executionId}' exceeded its {$timeoutSeconds}s execution timeout (elapsed: {$elapsed}s).",
            '/workflows',
            self::ERROR_CODE,
        );
    }

    public function getErrorCode(): string
    {
        return $this->codeId;
    }
}
