<?php

declare(strict_types=1);

namespace Alama\Arazzo\Protocol\Http\Exceptions;

use InvalidArgumentException;

/**
 * Thrown when an HTTP policy value object is constructed with invalid bounds or invariants.
 */
final class InvalidHttpPolicyException extends InvalidArgumentException
{
    public static function nonPositiveRequestsPerSecond(float $value): self
    {
        return new self("RateLimitPolicy requestsPerSecond must be greater than zero, got {$value}.");
    }

    public static function nonPositiveLimit(string $field, int|float $value): self
    {
        return new self("HttpPolicy {$field} must be positive, got {$value}.");
    }

    public static function emptyHeaderName(): self
    {
        return new self('IdempotencyPolicy headerName must be a non-empty string.');
    }

    public static function negativeMaxRedirects(int $value): self
    {
        return new self("RedirectPolicy maxRedirects must be zero or greater, got {$value}.");
    }

    public static function emptyAllowedSchemes(): self
    {
        return new self('NetworkPolicy allowedSchemes must contain at least one URI scheme.');
    }

    public static function emptySourceName(): self
    {
        return new self('HttpPolicy source override key must be a non-empty source description name.');
    }

    public static function nestedSourceOverrides(string $sourceName): self
    {
        return new self("HttpPolicy override for source '{$sourceName}' cannot contain nested source overrides.");
    }
}
