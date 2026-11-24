<?php

declare(strict_types=1);

namespace Alama\Arazzo\Document\Plugins\ValidationRule;

use Alama\Arazzo\Contracts\Attributes\AsValidationRulePlugin;
use Alama\Arazzo\Contracts\Interfaces\ValidationRuleInterface;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Parameter;
use Alama\Arazzo\Contracts\Spec\Reusable;
use Alama\Arazzo\Contracts\Validation\ErrorCollector;
use Alama\Arazzo\Contracts\Validation\SymbolTable;

/**
 * @internal stays out of the advertised contract; not part of the public API surface
 */
#[AsValidationRulePlugin]
final class StepParameterRequiresInRule implements ValidationRuleInterface
{
    public function check(ArazzoDocument $doc, SymbolTable $symbols, ErrorCollector $errors): void
    {
        foreach ($doc->workflows as $wi => $w) {
            foreach ($w->steps as $si => $s) {
                // Only HTTP operation steps require `in`; workflow steps and
                // workflow-level parameters may pass values without it.
                if (!$s->isHttpOperation()) {
                    continue;
                }

                $this->checkStepParameters($s->io->parameters, $errors, "/workflows/{$wi}/steps/{$si}/parameters");
            }
        }
    }

    /**
     * @param  list<Parameter|Reusable>  $params
     */
    private function checkStepParameters(array $params, ErrorCollector $errors, string $base): void
    {
        foreach ($params as $pi => $p) {
            if ($p instanceof Reusable) {
                continue;
            }

            if ($p->in === null) {
                $errors->error(
                    $this->code(),
                    "Parameter '{$p->name}' must have an 'in' value.",
                    "{$base}/{$pi}/in",
                );
            }
        }
    }

    public function code(): string
    {
        return 'step.parameter_requires_in';
    }
}
