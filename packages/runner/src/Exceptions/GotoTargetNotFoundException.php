<?php

declare(strict_types=1);

namespace Alama\Arazzo\Runner\Exceptions;

final class GotoTargetNotFoundException extends RunnerException
{
    public static function unknownStep(string $stepId, string $workflowId): self
    {
        return new self(
            "Action references unknown stepId '{$stepId}' in workflow '{$workflowId}'.",
            '/workflows',
            'runner.goto_unknown_step',
        );
    }

    public static function unknownWorkflow(string $actionName, string $workflowId): self
    {
        return new self(
            "Goto action '{$actionName}' references unknown workflowId '{$workflowId}'.",
            '/workflows',
            'runner.goto_unknown_workflow',
        );
    }

    public static function wrongReusableTarget(string $reference, string $type): self
    {
        return new self(
            "Reusable reference '{$reference}' does not target components.{$type}.",
            '/components',
            'runner.reusable_wrong_target',
        );
    }

    public static function unresolvableReusable(string $reference): self
    {
        return new self(
            "Reusable reference '{$reference}' does not resolve.",
            '/components',
            'runner.reusable_unresolvable',
        );
    }
}
