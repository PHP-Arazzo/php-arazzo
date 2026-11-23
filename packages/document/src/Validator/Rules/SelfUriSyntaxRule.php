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
final class SelfUriSyntaxRule implements ValidationRuleInterface
{
    public function check(ArazzoDocument $doc, SymbolTable $symbols, ErrorCollector $errors): void
    {
        if ($doc->self === null) {
            return;
        }

        $self = $doc->self;

        // An empty or whitespace-only string is not a URI-reference.
        if (trim($self) === '') {
            $errors->error(
                $this->code(),
                "Invalid \$self URI: '{$self}'",
                '/$self',
            );

            return;
        }

        // $self MUST NOT contain a fragment identifier.
        if (str_contains($self, '#')) {
            $errors->error(
                $this->code(),
                "Invalid \$self URI: '{$self}'",
                '/$self',
            );

            return;
        }

        // $self is a URI-reference (RFC 3986 §4.1): an absolute URI or a
        // relative reference. Whitespace is never valid, and parse_url()
        // returns false for strings that cannot be parsed as a URI-reference.
        if (preg_match('/\s/', $self) !== 0 || parse_url($self) === false) {
            $errors->error(
                $this->code(),
                "Invalid \$self URI: '{$self}'",
                '/$self',
            );
        }
    }

    public function code(): string
    {
        return 'document.self_uri_syntax';
    }
}
