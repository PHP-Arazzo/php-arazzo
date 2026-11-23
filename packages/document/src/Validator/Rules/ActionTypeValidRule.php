<?php

declare(strict_types=1);

namespace Alama\Arazzo\Document\Validator\Rules;

use Alama\Arazzo\Contracts\Attributes\AsValidationRule;
use Alama\Arazzo\Contracts\Interfaces\ValidationRuleInterface;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Validation\ErrorCollector;
use Alama\Arazzo\Contracts\Validation\SymbolTable;

/**
 * @internal stays out of the advertised contract; not part of the public API surface
 */
#[AsValidationRule]
final class ActionTypeValidRule implements ValidationRuleInterface
{
    public function code(): string
    {
        return 'action.type_valid';
    }

    public function check(ArazzoDocument $doc, SymbolTable $symbols, ErrorCollector $errors): void {}
}
