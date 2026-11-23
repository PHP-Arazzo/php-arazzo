<?php

declare(strict_types=1);

namespace Alama\Arazzo\Document\Validator\Rules;

use Alama\Arazzo\Contracts\Attributes\AsValidationRule;
use Alama\Arazzo\Contracts\Interfaces\ValidationRuleInterface;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Validation\ErrorCollector;
use Alama\Arazzo\Contracts\Validation\SymbolTable;

/**
 * @internal stays out of the advertised contract; not part of the public API surface
 */
#[AsValidationRule]
final class WorkflowUniqueIdRule implements ValidationRuleInterface
{
    public function check(ArazzoDocument $doc, SymbolTable $symbols, ErrorCollector $errors): void
    {
        $seen = [];
        foreach ($doc->workflows as $i => $w) {
            if (isset($seen[$w->workflowId])) {
                $errors->error($this->code(), "Duplicate workflowId '{$w->workflowId}'.", "/workflows/{$i}/workflowId");
            }
            $seen[$w->workflowId] = true;
        }
    }

    public function code(): string
    {
        return 'workflow.unique_id';
    }
}
