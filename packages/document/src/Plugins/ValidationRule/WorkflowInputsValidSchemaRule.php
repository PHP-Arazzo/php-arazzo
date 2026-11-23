<?php

declare(strict_types=1);

namespace Alama\Arazzo\Document\Plugins\ValidationRule;

use Alama\Arazzo\Contracts\Attributes\AsValidationRulePlugin;
use Alama\Arazzo\Contracts\Interfaces\ValidationRuleInterface;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Support\InputSchemaResolver;
use Alama\Arazzo\Contracts\Validation\ErrorCollector;
use Alama\Arazzo\Contracts\Validation\SymbolTable;

/**
 * @internal stays out of the advertised contract; not part of the public API surface
 */
#[AsValidationRulePlugin]
final class WorkflowInputsValidSchemaRule implements ValidationRuleInterface
{
    public function check(ArazzoDocument $doc, SymbolTable $symbols, ErrorCollector $errors): void
    {
        foreach ($doc->workflows as $i => $w) {
            if (!isset($w->inputs)) {
                continue;
            }
            $path = "/workflows/{$i}/inputs";

            // Local references into components.inputs resolve before shape checks.
            $inputs = InputSchemaResolver::resolve($w->inputs, $doc->components->inputs) ?? [];

            if (array_is_list($inputs) && $inputs !== []) {
                $errors->error($this->code(), 'workflow inputs must be an object.', $path);

                continue;
            }
            if (isset($inputs['type']) && $inputs['type'] !== 'object') {
                $errors->error($this->code(), "workflow inputs schema must be of type 'object'.", $path.'/type');

                continue;
            }
            if (isset($inputs['properties']) && !is_array($inputs['properties'])) {
                $errors->error($this->code(), 'workflow inputs.properties must be an object.', $path.'/properties');
            }
        }
    }

    public function code(): string
    {
        return 'workflow.inputs_valid_schema';
    }
}
