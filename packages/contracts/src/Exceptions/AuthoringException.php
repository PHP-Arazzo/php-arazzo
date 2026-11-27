<?php

declare(strict_types=1);

namespace Alama\Arazzo\Contracts\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Thrown or recorded for workflow authoring or preflight validation errors.
 */
final class AuthoringException extends RuntimeException implements ArazzoExecutionExceptionInterface
{
    public static function preflightFailure(string $details, ?Throwable $previous = null): self
    {
        return new self($details, 0, $previous);
    }
}
