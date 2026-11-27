<?php

declare(strict_types=1);

namespace Alama\Arazzo\Sources\Exceptions;

final class InvalidAsyncApiSourceException extends SourceResolutionException
{
    public static function invalidJson(): self
    {
        return new self('AsyncAPI source document contains invalid JSON/YAML.');
    }

    public static function channelNotFound(string $channel): self
    {
        return new self("Channel '{$channel}' not found in AsyncAPI source document.");
    }

    public static function missingChannels(): self
    {
        return new self('AsyncAPI source document does not declare any channels.');
    }

    public static function invalidFormat(string $message): self
    {
        return new self("Invalid AsyncAPI source format: {$message}");
    }
}
