<?php

declare(strict_types=1);

namespace Alama\Arazzo\Contracts\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Thrown or recorded when transport-level I/O failures occur (connection timeout,
 * connection refused, DNS lookup failure, or outbound rate limiting).
 */
final class TransportException extends RuntimeException implements ArazzoExecutionExceptionInterface
{
    public function __construct(
        string $message,
        public readonly string $targetUrl = '',
        public readonly string $requestMethod = '',
        ?Throwable $previous = null,
        int $code = 0,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public static function timeout(string $targetUrl, string $requestMethod, ?Throwable $previous = null): self
    {
        return new self(
            "Transport request to '{$targetUrl}' timed out [{$requestMethod}].",
            $targetUrl,
            $requestMethod,
            $previous,
        );
    }

    public static function connectionRefused(string $targetUrl, string $requestMethod, ?Throwable $previous = null): self
    {
        return new self(
            "Transport connection to '{$targetUrl}' was refused [{$requestMethod}].",
            $targetUrl,
            $requestMethod,
            $previous,
        );
    }

    public static function networkError(string $targetUrl, string $requestMethod, string $details, ?Throwable $previous = null): self
    {
        return new self(
            "Transport network error dispatching '{$requestMethod}' to '{$targetUrl}': {$details}",
            $targetUrl,
            $requestMethod,
            $previous,
        );
    }

    public static function rateLimited(string $targetUrl, string $requestMethod, string $details, ?Throwable $previous = null): self
    {
        return new self(
            "Transport rate limit exceeded dispatching '{$requestMethod}' to '{$targetUrl}': {$details}",
            $targetUrl,
            $requestMethod,
            $previous,
        );
    }
}
