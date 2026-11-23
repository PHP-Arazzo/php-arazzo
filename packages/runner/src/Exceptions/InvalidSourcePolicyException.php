<?php

declare(strict_types=1);

namespace Alama\Arazzo\Runner\Exceptions;

use Alama\Arazzo\Runner\Policy\ExecutionPolicy;

/**
 * Thrown fail-fast before any step executes when an {@see ExecutionPolicy}
 * addresses an invalid, unknown, or non-API source description key.
 */
final class InvalidSourcePolicyException extends RunnerException
{
    public static function dottedKeyReserved(string $key): self
    {
        return new self(
            "Source policy key '{$key}' uses dotted notation, which is reserved for nested Arazzo source descriptions.",
        );
    }

    public static function unknownSource(string $key): self
    {
        return new self(
            "Source policy key '{$key}' does not match any sourceDescription declared in the Arazzo document.",
        );
    }

    public static function arazzoSourceNotAddressable(string $key): self
    {
        return new self(
            "Source policy key '{$key}' targets an 'arazzo' sourceDescription; source policies may only address API source descriptions.",
        );
    }

    public static function duplicateSourceUrl(string $firstKey, string $secondKey, string $url): self
    {
        return new self(
            "Source policies '{$firstKey}' and '{$secondKey}' target the same source URL '{$url}'; configure a single sourceDescription name per API.",
        );
    }
}
