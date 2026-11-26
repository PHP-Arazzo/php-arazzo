<?php

declare(strict_types=1);

namespace Alama\Arazzo\Expression\Enum;

enum ExpressionContext: string
{
    case Parameters = 'parameters';
    case RequestBody = 'requestBody';
    case Criteria = 'criteria';
    case Outputs = 'outputs';
    case OnSuccess = 'onSuccess';
    case OnFailure = 'onFailure';
    case WorkflowParameters = 'wf.parameters';
    case WorkflowOutputs = 'wf.outputs';
    case WorkflowSuccessActions = 'wf.successActions';
    case WorkflowFailureActions = 'wf.failureActions';
    case Components = 'components';

    public function allowsRuntimeHttpReference(): bool
    {
        return match ($this) {
            self::Criteria,
            self::Outputs,
            self::OnSuccess,
            self::OnFailure => true,
            default => false,
        };
    }
}
