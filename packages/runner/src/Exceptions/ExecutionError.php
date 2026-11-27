<?php

declare(strict_types=1);

namespace Alama\Arazzo\Runner\Exceptions;

final class ExecutionError extends RunnerException
{
    public static function stateMissing(string $executionId): self
    {
        return new self("No persisted state for execution '{$executionId}'.", '', 'runner.state_missing');
    }

    public static function ambiguousActionTarget(string $actionName): self
    {
        return new self(
            "Action '{$actionName}' declares both stepId and workflowId; they are mutually exclusive.",
            '',
            'ambiguous-target',
        );
    }

    public static function stepFailed(string $stepId): self
    {
        return new self("Step '{$stepId}' failed", '', 'runner.step_failed');
    }

    public static function workflowFailedAtStep(string $workflowId, string $stepId): self
    {
        return new self("Workflow '{$workflowId}' failed at step '{$stepId}'.", '', 'runner.workflow_failed');
    }

    public static function workflowEndedInFailure(string $workflowId, string $stepId): self
    {
        return new self("Workflow '{$workflowId}' ended in failure at step '{$stepId}'", '', 'runner.workflow_failed');
    }

    public static function dependencyFailed(string $dependencyId): self
    {
        return new self("Dependency '{$dependencyId}' failed.", '', 'runner.dependency_failed');
    }

    public static function missingDependencyOutput(string $stepId, string $outputKey, ?string $depStepId = null): self
    {
        $message = $depStepId !== null
            ? "Step '{$stepId}' requires output '{$outputKey}' from upstream step '{$depStepId}', but it is missing."
            : "Step '{$stepId}' requires missing dependency output '{$outputKey}'.";

        return new self($message, '', 'step.missing_dependency_output');
    }
}
