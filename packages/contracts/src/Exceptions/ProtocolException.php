<?php

declare(strict_types=1);

namespace Alama\Arazzo\Contracts\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Thrown or recorded when protocol-level framing or serialization failures occur
 * (malformed response body, unexpected content type, invalid wire payload).
 */
final class ProtocolException extends RuntimeException implements ArazzoExecutionExceptionInterface
{
    public function __construct(
        string $message,
        public readonly string $targetUrl = '',
        ?Throwable $previous = null,
        int $code = 0,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public static function malformedResponse(string $targetUrl, string $details, ?Throwable $previous = null): self
    {
        return new self(
            "Malformed protocol response from '{$targetUrl}': {$details}",
            $targetUrl,
            $previous,
        );
    }

    public static function contentTypeMismatch(string $targetUrl, string $expected, string $actual, ?Throwable $previous = null): self
    {
        return new self(
            "Protocol content-type mismatch for '{$targetUrl}': expected '{$expected}', got '{$actual}'.",
            $targetUrl,
            $previous,
        );
    }
}
