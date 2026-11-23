<?php

declare(strict_types=1);

namespace Alama\Arazzo\Document\Plugins\ValidationRule;

use Alama\Arazzo\Contracts\Attributes\AsValidationRulePlugin;
use Alama\Arazzo\Contracts\Interfaces\ValidationRuleInterface;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Enum\ParameterIn;
use Alama\Arazzo\Contracts\Spec\Parameter;
use Alama\Arazzo\Contracts\Spec\Reusable;
use Alama\Arazzo\Contracts\Validation\ErrorCollector;
use Alama\Arazzo\Contracts\Validation\SymbolTable;

/**
 * @internal stays out of the advertised contract; not part of the public API surface
 */
#[AsValidationRulePlugin]
final class StepParameterDuplicateRule implements ValidationRuleInterface
{
    public function check(ArazzoDocument $doc, SymbolTable $symbols, ErrorCollector $errors): void
    {
        // Check for duplicate parameters within each step
        foreach ($doc->workflows as $wi => $w) {
            foreach ($w->steps as $si => $s) {
                $this->checkStepParameters(
                    $s->io->parameters,
                    $errors,
                    "/workflows/{$wi}/steps/{$si}/parameters",
                    'step.parameters_duplicate',
                );
            }
        }

        // Check for duplicate parameters within workflow parameters scope
        foreach ($doc->workflows as $wi => $w) {
            $this->checkStepParameters(
                $w->parameters,
                $errors,
                "/workflows/{$wi}/parameters",
                'workflow.parameters_duplicate',
            );
        }
    }

    /**
     * @param  list<Parameter|Reusable>  $params
     */
    private function checkStepParameters(array $params, ErrorCollector $errors, string $base, string $code): void
    {
        // Filter out reusable parameters - they are resolved externally
        $nonReusableParams = [];
        foreach ($params as $pi => $p) {
            if ($p instanceof Reusable) {
                continue;
            }
            $nonReusableParams[] = $p;
        }

        // Check for duplicate (name, in) pairs within the same scope
        $seen = [];
        foreach ($nonReusableParams as $pi => $p) {
            $in = $p->in;
            $inValue = $in instanceof ParameterIn ? $in->value : null;
            $key = $p->name.':'.($inValue ?? 'null');
            if (isset($seen[$key])) {
                $errors->error(
                    $code,
                    "Parameter '{$p->name}' with 'in' value '".($inValue ?? 'null')."' is duplicated at indices {$seen[$key]} and {$pi}.",
                    $base,
                );
            }
            $seen[$key] = $pi;
        }
    }

    public function code(): string
    {
        return 'step.parameters_duplicate';
    }
}
