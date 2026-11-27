<?php

declare(strict_types=1);

namespace Alama\Arazzo\Runner\Exceptions;

final class UnknownStepException extends RunnerException
{
    /**
     * @param  list<string>  $validStepIds
     */
    public static function forId(string $stepId, array $validStepIds = [], ?string $workflowId = null): self
    {
        $validList = $validStepIds !== [] ? ' Valid step IDs: '.implode(', ', $validStepIds).'.' : '';
        $context = $workflowId !== null ? " in workflow '{$workflowId}'" : '';

        return new self("Unknown step '{$stepId}'{$context}.{$validList}", '', 'runner.unknown_step');
    }
}
