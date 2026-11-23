<?php

declare(strict_types=1);

namespace Alama\Arazzo\Document\Plugins\ValidationRule;

use Alama\Arazzo\Contracts\Attributes\AsValidationRulePlugin;
use Alama\Arazzo\Contracts\Interfaces\ValidationRuleInterface;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Enum\CriterionType;
use Alama\Arazzo\Contracts\Validation\ErrorCollector;
use Alama\Arazzo\Contracts\Validation\SymbolTable;

/**
 * @internal stays out of the advertised contract; not part of the public API surface
 */
#[AsValidationRulePlugin]
final class StepCriteriaTypeContextRule implements ValidationRuleInterface
{
    public function check(ArazzoDocument $doc, SymbolTable $symbols, ErrorCollector $errors): void
    {
        $needsContext = [CriterionType::JsonPath, CriterionType::XPath, CriterionType::Regex];
        foreach ($doc->workflows as $i => $w) {
            foreach ($w->steps as $j => $s) {
                foreach ($s->io->successCriteria as $k => $c) {
                    if ($c->type !== null && in_array($c->type, $needsContext, true) && ($c->context === null || trim($c->context) === '')) {
                        $errors->error(
                            $this->code(),
                            "successCriteria[{$k}] type '{$c->type->value}' requires a context expression.",
                            "/workflows/{$i}/steps/{$j}/successCriteria/{$k}/context",
                        );
                    }
                }
            }
        }
    }

    public function code(): string
    {
        return 'step.criteria_type_context';
    }
}
