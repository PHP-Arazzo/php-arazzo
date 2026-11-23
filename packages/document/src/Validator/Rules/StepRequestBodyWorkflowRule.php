<?php

declare(strict_types=1);

namespace Alama\Arazzo\Document\Validator\Rules;

use Alama\Arazzo\Contracts\Attributes\AsValidationRule;
use Alama\Arazzo\Contracts\Interfaces\ValidationRuleInterface;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\RequestBody;
use Alama\Arazzo\Contracts\Validation\ErrorCollector;
use Alama\Arazzo\Contracts\Validation\SymbolTable;

/**
 * @internal stays out of the advertised contract; not part of the public API surface
 */
#[AsValidationRule]
final class StepRequestBodyWorkflowRule implements ValidationRuleInterface
{
    public function check(ArazzoDocument $doc, SymbolTable $symbols, ErrorCollector $errors): void
    {
        foreach ($doc->workflows as $wi => $w) {
            foreach ($w->steps as $si => $s) {
                // If the step targets a workflow (has workflowId), it must not have requestBody
                if ($s->target->workflowId !== null && $s->io->requestBody !== null) {
                    $errors->error(
                        $this->code(),
                        "Step '{$s->stepId}' targeting a workflow must not carry requestBody.",
                        "/workflows/{$wi}/steps/{$si}/requestBody",
                    );
                }
            }
        }
    }

    public function code(): string
    {
        return 'step.request_body_workflow';
    }
}
