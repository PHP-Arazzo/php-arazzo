<?php

declare(strict_types=1);

namespace Alama\Arazzo\Sources\Exceptions;

use InvalidArgumentException;

final class InvalidFetchPolicyException extends InvalidArgumentException
{
    public static function nonPositiveConnectTimeout(): self
    {
        return new self('FetchPolicy connectTimeout must be greater than 0.');
    }

    public static function nonPositiveTimeout(): self
    {
        return new self('FetchPolicy timeout must be greater than 0.');
    }

    public static function nonPositiveMaxBytes(): self
    {
        return new self('FetchPolicy maxBytes must be at least 1.');
    }
}
