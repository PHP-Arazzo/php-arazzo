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
final class SourceUniqueNameRule implements ValidationRuleInterface
{
    public function check(ArazzoDocument $doc, SymbolTable $symbols, ErrorCollector $errors): void
    {
        $seen = [];
        foreach ($doc->sourceDescriptions as $i => $s) {
            if (isset($seen[$s->name])) {
                $errors->error($this->code(), "Duplicate sourceDescription name '{$s->name}'.", "/sourceDescriptions/{$i}/name");
            }
            $seen[$s->name] = true;
        }
    }

    public function code(): string
    {
        return 'source.unique_name';
    }
}
