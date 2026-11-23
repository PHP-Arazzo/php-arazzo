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
final class StepIdPatternRule implements ValidationRuleInterface
{
    public function check(ArazzoDocument $doc, SymbolTable $symbols, ErrorCollector $errors): void
    {
        foreach ($doc->workflows as $i => $w) {
            foreach ($w->steps as $j => $s) {
                if (preg_match('/^[A-Za-z0-9_\-]+$/', $s->stepId) !== 1) {
                    $errors->error(
                        $this->code(),
                        "stepId '{$s->stepId}' must match [A-Za-z0-9_-]+.",
                        "/workflows/{$i}/steps/{$j}/stepId",
                    );
                }
            }
        }
    }

    public function code(): string
    {
        return 'step.id_pattern';
    }
}
