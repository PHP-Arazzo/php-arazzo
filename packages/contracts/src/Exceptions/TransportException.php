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
    public const string CODE_PREFIX = 'transport.';

    public const string CODE_RATE_LIMITED = 'transport.rate_limited';

    public function __construct(
        string $message,
        public readonly string $targetUrl = '',
        public readonly string $requestMethod = '',
        ?Throwable $previous = null,
        public readonly ?string $errorCode = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getErrorCode(): ?string
    {
        return $this->errorCode;
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
            self::CODE_RATE_LIMITED,
        );
    }

    public static function policyViolation(
        string $targetUrl,
        string $requestMethod,
        string $errorCode,
        string $details,
        ?Throwable $previous = null,
    ): self {
        return new self(
            $details,
            $targetUrl,
            $requestMethod,
            $previous,
            $errorCode,
        );
    }

    public static function fromThrowable(Throwable $exception, string $targetUrl = '', string $requestMethod = ''): self
    {
        if ($exception instanceof self) {
            return $exception;
        }

        $errorCode = self::extractErrorCode($exception);
        if ($errorCode === self::CODE_RATE_LIMITED) {
            return self::rateLimited($targetUrl, $requestMethod, $exception->getMessage(), $exception);
        }

        if ($errorCode !== null && str_starts_with($errorCode, self::CODE_PREFIX)) {
            return self::policyViolation($targetUrl, $requestMethod, $errorCode, $exception->getMessage(), $exception);
        }

        $message = strtolower($exception->getMessage());

        if (str_contains($message, 'connection refused')) {
            return self::connectionRefused($targetUrl, $requestMethod, $exception);
        }

        if (str_contains($message, 'timed out') || str_contains($message, 'timeout')) {
            return self::timeout($targetUrl, $requestMethod, $exception);
        }

        if (str_contains($message, 'rate limit')) {
            return self::rateLimited($targetUrl, $requestMethod, $exception->getMessage(), $exception);
        }

        return self::networkError($targetUrl, $requestMethod, $exception->getMessage(), $exception);
    }

    public static function extractErrorCode(Throwable $exception): ?string
    {
        if (!method_exists($exception, 'getErrorCode')) {
            return null;
        }

        $code = $exception->getErrorCode();

        return is_string($code) && $code !== '' ? $code : null;
    }
}
