<?php

declare(strict_types=1);

namespace Alama\Arazzo\Sources\Exceptions;

use InvalidArgumentException;

final class InvalidOpenApiSourceException extends InvalidArgumentException
{
    public static function invalidJson(): self
    {
        return new self('Failed to decode source content as JSON.');
    }

    public static function unsupportedVersion(): self
    {
        return new self('Unsupported or missing OpenAPI/Swagger version in document.');
    }

    public static function pathNotFound(string $path): self
    {
        return new self("Path '{$path}' not found in document.");
    }

    public static function methodNotFound(string $method, string $path): self
    {
        return new self("Method '{$method}' not found for path '{$path}'.");
    }
}
