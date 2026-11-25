<?php

declare(strict_types=1);

namespace Alama\Arazzo\Runner\Exceptions;

final class StepBudgetExceededException extends RunnerException
{
    public static function exceeded(string $executionId, int $maxSteps): self
    {
        return new self(
            "Execution '{$executionId}' exceeded its {$maxSteps}-step budget.",
            '/workflows',
            'runner.step_budget_exceeded',
        );
    }
}
