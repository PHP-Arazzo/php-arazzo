<?php

declare(strict_types=1);

namespace Alama\Arazzo\Document\Plugins\ValidationRule;

use Alama\Arazzo\Contracts\Attributes\AsValidationRulePlugin;
use Alama\Arazzo\Contracts\Interfaces\ValidationRuleInterface;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Validation\ErrorCollector;
use Alama\Arazzo\Contracts\Validation\SymbolTable;

/**
 * Validates that source descriptions with omitted types can be inferred under resolve mode.
 *
 * @internal stays out of the advertised contract; not part of the public API surface
 */
#[AsValidationRulePlugin]
final class SourceTypeInferenceFailedRule implements ValidationRuleInterface
{
    public function code(): string
    {
        return 'source.type_inference_failed';
    }

    public function check(ArazzoDocument $doc, SymbolTable $symbols, ErrorCollector $errors): void
    {
        if (!$symbols->sourceResolutions->isResolved()) {
            return;
        }

        foreach ($doc->sourceDescriptions as $i => $source) {
            $res = $symbols->sourceResolutions->get($source->name);
            if ($res !== null && $res->isTypeInferenceFailed()) {
                $errors->error(
                    $this->code(),
                    "Failed to infer source type for '{$source->name}' at '{$source->url}': unrecognized document format",
                    "/sourceDescriptions/{$i}/url",
                );
            }
        }
    }
}
