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
final class StepUniqueIdRule implements ValidationRuleInterface
{
    public function check(ArazzoDocument $doc, SymbolTable $symbols, ErrorCollector $errors): void
    {
        foreach ($doc->workflows as $i => $w) {
            $seen = [];
            foreach ($w->steps as $j => $s) {
                if (isset($seen[$s->stepId])) {
                    $errors->error(
                        $this->code(),
                        "Duplicate stepId '{$s->stepId}' in workflow '{$w->workflowId}'.",
                        "/workflows/{$i}/steps/{$j}/stepId",
                    );
                }
                $seen[$s->stepId] = true;
            }
        }
    }

    public function code(): string
    {
        return 'step.unique_id';
    }
}
