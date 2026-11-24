<?php

declare(strict_types=1);

namespace Alama\Arazzo\Engine\Exceptions;

use InvalidArgumentException;

final class InvalidRetryPolicyException extends InvalidArgumentException
{
    public static function nonPositiveMaxAttempts(): self
    {
        return new self('RetryPolicy maxAttempts must be at least 1.');
    }

    public static function invalidBackoffMultiplier(): self
    {
        return new self('RetryPolicy backoffMultiplier must be greater than 0.');
    }
}
