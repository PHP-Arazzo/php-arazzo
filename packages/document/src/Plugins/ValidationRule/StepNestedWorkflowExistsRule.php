<?php

declare(strict_types=1);

namespace Alama\Arazzo\Document\Plugins\ValidationRule;

use Alama\Arazzo\Contracts\Attributes\AsValidationRulePlugin;
use Alama\Arazzo\Contracts\Interfaces\ValidationRuleInterface;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Validation\ErrorCollector;
use Alama\Arazzo\Contracts\Validation\SymbolTable;

/**
 * @internal stays out of the advertised contract; not part of the public API surface
 */
#[AsValidationRulePlugin]
final class StepNestedWorkflowExistsRule implements ValidationRuleInterface
{
    public function check(ArazzoDocument $doc, SymbolTable $symbols, ErrorCollector $errors): void
    {
        foreach ($doc->workflows as $i => $w) {
            foreach ($w->steps as $j => $s) {
                if ($s->target->workflowId === null) {
                    continue;
                }
                if (!isset($symbols->workflows[$s->target->workflowId])) {
                    if (!$doc->hasExternalSourceFor($s->target->workflowId)) {
                        $errors->error(
                            $this->code(),
                            "step.workflowId '{$s->target->workflowId}' does not resolve to a declared local workflow or an external arazzo source.",
                            "/workflows/{$i}/steps/{$j}/workflowId",
                        );
                    }
                }
            }
        }
    }

    public function code(): string
    {
        return 'step.nested_workflow_exists';
    }
}
