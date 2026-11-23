<?php

declare(strict_types=1);

namespace Alama\Arazzo\Document\Validator\Rules;

use Alama\Arazzo\Contracts\Attributes\AsValidationRule;
use Alama\Arazzo\Contracts\Interfaces\ValidationRuleInterface;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Validation\ErrorCollector;
use Alama\Arazzo\Contracts\Validation\SymbolTable;

/**
 * Arazzo requires at least one Source Description: "an info field, a
 * sourceDescriptions field with at least one defined Source Description".
 *
 * @internal stays out of the advertised contract; not part of the public API surface
 */
#[AsValidationRule]
final class DocumentSourceDescriptionsPresentRule implements ValidationRuleInterface
{
    public function check(ArazzoDocument $doc, SymbolTable $symbols, ErrorCollector $errors): void
    {
        if ($doc->sourceDescriptions !== []) {
            return;
        }

        $errors->error(
            $this->code(),
            'Document must declare at least one sourceDescription.',
            '/sourceDescriptions',
        );
    }

    public function code(): string
    {
        return 'document.source_descriptions_present';
    }
}
