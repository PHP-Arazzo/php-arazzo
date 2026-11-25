<?php

declare(strict_types=1);

namespace Alama\Arazzo\Sources\Exceptions;

use Throwable;

class SourceParseException extends SourceResolutionException
{
    public static function parseFailed(string $sourceName, Throwable $previous): self
    {
        return new self("Failed to parse source '{$sourceName}': ".$previous->getMessage(), 0, $previous);
    }

    public static function rootMustBeArray(string $sourceName): self
    {
        return new self("Parsed document for '{$sourceName}' must be an array.");
    }
}
