<?php

declare(strict_types=1);

namespace Alama\Arazzo\Document\Parser;

use Alama\Arazzo\Contracts\Spec\Action\FailureAction;
use Alama\Arazzo\Contracts\Spec\Action\FailureEndAction;
use Alama\Arazzo\Contracts\Spec\Action\FailureGotoAction;
use Alama\Arazzo\Contracts\Spec\Action\RetryAction;
use Alama\Arazzo\Contracts\Spec\Action\SubWorkflowFailureAction;
use Alama\Arazzo\Contracts\Spec\Reusable;
use Alama\Arazzo\Document\Parser\Exceptions\ParserException;

/**
 * Parses Arazzo failure actions (goto, end, retry, invoke).
 *
 * @internal stays out of the advertised contract; not part of the public API surface
 */
final class FailureActionParser extends FieldParser
{
    public function __construct(
        private readonly ValueParser $values,
    ) {}

    public function parseFailureAction(mixed $node, ParseContext $ctx): FailureAction|Reusable
    {
        $obj = $this->requireObjectMap($node, $ctx);
        if (array_key_exists('reference', $obj)) {
            return $this->values->parseReusable($obj, $ctx);
        }
        $name = $this->requireString($obj, 'name', $ctx);
        $type = $this->requireString($obj, 'type', $ctx);
        $criteria = $this->values->parseCriteriaList($obj, $ctx);

        return match ($type) {
            'goto' => new FailureGotoAction(
                name: $name,
                stepId: $this->optionalString($obj, 'stepId', $ctx),
                workflowId: $this->optionalString($obj, 'workflowId', $ctx),
                criteria: $criteria,
                parameters: $this->values->parseActionParameters($obj, $ctx),
            ),
            'end' => new FailureEndAction($name, $criteria),
            'retry' => new RetryAction(
                name: $name,
                retryAfter: $this->optionalNumber($obj, 'retryAfter', $ctx),
                retryLimit: $this->optionalInt($obj, 'retryLimit', $ctx),
                stepId: $this->optionalString($obj, 'stepId', $ctx),
                workflowId: $this->optionalString($obj, 'workflowId', $ctx),
                criteria: $criteria,
            ),
            'invoke' => new SubWorkflowFailureAction(
                $name,
                $this->requireString($obj, 'workflowId', $ctx),
                $this->values->parseInvokeParameters($obj, $ctx),
                $criteria,
                $this->optionalString($obj, 'version', $ctx),
            ),
            default => throw ParserException::invalidActionType($ctx->push('type'), $type),
        };
    }
}
