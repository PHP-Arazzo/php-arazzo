<?php

declare(strict_types=1);

namespace Alama\Arazzo\Protocol\Soap\Exceptions;

use RuntimeException;

final class UnsupportedSourceVersionException extends RuntimeException
{
    public static function forVersion(string $version, string $sourceName): self
    {
        return new self("Unsupported source version '{$version}' for source '{$sourceName}'.");
    }
}
