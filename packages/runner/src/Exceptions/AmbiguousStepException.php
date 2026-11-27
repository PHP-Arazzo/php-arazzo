<?php

declare(strict_types=1);

namespace Alama\Arazzo\Runner\Exceptions;

final class AmbiguousStepException extends RunnerException
{
    /**
     * @param  list<string>  $matchingWorkflowIds
     */
    public static function forId(string $stepId, array $matchingWorkflowIds): self
    {
        $list = implode(', ', $matchingWorkflowIds);

        return new self(
            "Step '{$stepId}' is ambiguous; it exists in multiple workflows: {$list}. Please specify a workflowId.",
            '',
            'runner.ambiguous_step',
        );
    }
}
