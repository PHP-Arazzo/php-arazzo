<?php

declare(strict_types=1);

namespace Alama\Arazzo\Document\Parser;

use Alama\Arazzo\Contracts\Spec\Action\FailureAction;
use Alama\Arazzo\Contracts\Spec\Action\SubWorkflowSuccessAction;
use Alama\Arazzo\Contracts\Spec\Action\SuccessAction;
use Alama\Arazzo\Contracts\Spec\Action\SuccessEndAction;
use Alama\Arazzo\Contracts\Spec\Action\SuccessGotoAction;
use Alama\Arazzo\Contracts\Spec\Reusable;
use Alama\Arazzo\Document\Parser\Exceptions\ParserException;

/**
 * Parses success and failure actions (delegating failure-action variants to FailureActionParser).
 *
 * @internal stays out of the advertised contract; not part of the public API surface
 */
final class ActionParser extends FieldParser
{
    private readonly FailureActionParser $failureActions;

    public function __construct(
        private readonly ValueParser $values,
    ) {
        $this->failureActions = new FailureActionParser($values);
    }

    public function parseSuccessAction(mixed $node, ParseContext $ctx): SuccessAction|Reusable
    {
        $obj = $this->requireObjectMap($node, $ctx);
        if (array_key_exists('reference', $obj)) {
            return $this->values->parseReusable($obj, $ctx);
        }
        $name = $this->requireString($obj, 'name', $ctx);
        $type = $this->requireString($obj, 'type', $ctx);
        $criteria = $this->values->parseCriteriaList($obj, $ctx);

        return match ($type) {
            'goto' => new SuccessGotoAction(
                name: $name,
                stepId: $this->optionalString($obj, 'stepId', $ctx),
                workflowId: $this->optionalString($obj, 'workflowId', $ctx),
                criteria: $criteria,
                parameters: $this->values->parseActionParameters($obj, $ctx),
            ),
            'end' => new SuccessEndAction($name, $criteria),
            'invoke' => new SubWorkflowSuccessAction(
                $name,
                $this->requireString($obj, 'workflowId', $ctx),
                $this->values->parseInvokeParameters($obj, $ctx),
                $criteria,
                $this->optionalString($obj, 'version', $ctx),
            ),
            default => throw ParserException::invalidActionType($ctx->push('type'), $type),
        };
    }

    public function parseFailureAction(mixed $node, ParseContext $ctx): FailureAction|Reusable
    {
        return $this->failureActions->parseFailureAction($node, $ctx);
    }
}
