<?php

declare(strict_types=1);

namespace Alama\Arazzo\Contracts\Exceptions;

use Alama\Arazzo\Contracts\Support\Exceptions\ArazzoException;

final class UnresolvableReusableReferenceException extends ArazzoException
{
    public static function forParameter(string $reference): self
    {
        return new self(
            "Unresolvable reusable parameter reference '{$reference}'.",
            '',
            'reusable.parameter_not_found',
        );
    }
}
