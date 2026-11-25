<?php

declare(strict_types=1);

namespace Alama\Arazzo\Contracts\Exceptions;

use InvalidArgumentException;

final class InvalidSpecVersionException extends InvalidArgumentException
{
    public static function unsupported(string $raw): self
    {
        return new self(
            "Unsupported arazzo version '{$raw}'; expected 1.0.x, 1.1.x, or 1.2.x.",
        );
    }
}
