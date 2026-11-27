<?php

declare(strict_types=1);

namespace Alama\Arazzo\Protocol\Http\Exceptions;

use Alama\Arazzo\Engine\Exceptions\EngineException;

/**
 * Thrown when an outbound HTTP request exceeds configured rate limits
 * or concurrency caps and fail-fast is enabled.
 *
 * @internal stays out of the advertised contract; not part of the public API surface
 */
final class RateLimitExceededException extends EngineException
{
    public static function rateLimited(string $target, float $requestsPerSecond): self
    {
        return new self(
            "Outbound request to '{$target}' rate-limited ({$requestsPerSecond} req/s exceeded).",
            '/',
            'transport.rate_limited',
        );
    }

    public static function concurrencyCapped(string $target, int $maxConcurrent): self
    {
        return new self(
            "Outbound request to '{$target}' concurrency cap reached ({$maxConcurrent} in-flight requests).",
            '/',
            'transport.rate_limited',
        );
    }

    public function getErrorCode(): string
    {
        return $this->codeId;
    }
}
