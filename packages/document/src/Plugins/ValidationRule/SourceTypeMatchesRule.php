<?php

declare(strict_types=1);

namespace Alama\Arazzo\Document\Plugins\ValidationRule;

use Alama\Arazzo\Contracts\Attributes\AsValidationRulePlugin;
use Alama\Arazzo\Contracts\Interfaces\ValidationRuleInterface;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Validation\ErrorCollector;
use Alama\Arazzo\Contracts\Validation\SymbolTable;

/**
 * Enum enforcement happens at parse time; this rule exists so that a stable
 * `source.type_matches` code is reserved and so future non-parser-time checks
 * (e.g. "type: arazzo yet url points to an OpenAPI file") can land here.
 *
 * @internal stays out of the advertised contract; not part of the public API surface
 */
#[AsValidationRulePlugin]
final class SourceTypeMatchesRule implements ValidationRuleInterface
{
    public function code(): string
    {
        return 'source.type_matches';
    }

    public function check(ArazzoDocument $doc, SymbolTable $symbols, ErrorCollector $errors): void
    {
        if (!$symbols->sourceResolutions->isResolved()) {
            return;
        }

        foreach ($doc->sourceDescriptions as $i => $source) {
            $res = $symbols->sourceResolutions->get($source->name);
            if ($res !== null && $res->isTypeMismatch()) {
                $declared = $res->declaredType !== null
                    ? $res->declaredType->value
                    : ($source->type !== null ? $source->type->value : 'unknown');
                $detected = $res->detectedType !== null
                    ? $res->detectedType->value
                    : 'unknown';
                $errors->error(
                    $this->code(),
                    "Source '{$source->name}' declares type '{$declared}', but content matches '{$detected}'",
                    "/sourceDescriptions/{$i}/type",
                );
            }
        }
    }
}
