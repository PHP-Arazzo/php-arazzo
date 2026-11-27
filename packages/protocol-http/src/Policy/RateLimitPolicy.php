<?php

declare(strict_types=1);

namespace Alama\Arazzo\Protocol\Http\Policy;

use Alama\Arazzo\Protocol\Http\Enum\RateLimitScope;
use Alama\Arazzo\Protocol\Http\Exceptions\InvalidHttpPolicyException;

/**
 * Immutable value object governing outbound HTTP rate limits and in-flight concurrency caps.
 */
final readonly class RateLimitPolicy
{
    public const int DEFAULT_BURST = 1;

    public const int DEFAULT_MAX_CONCURRENT = 4;

    public const RateLimitScope DEFAULT_SCOPE = RateLimitScope::Host;

    public const bool DEFAULT_FAIL_FAST = false;

    public function __construct(
        public ?float $requestsPerSecond = null,
        public int $burst = self::DEFAULT_BURST,
        public int $maxConcurrent = self::DEFAULT_MAX_CONCURRENT,
        public RateLimitScope $scope = self::DEFAULT_SCOPE,
        public bool $failFast = self::DEFAULT_FAIL_FAST,
    ) {
        if ($this->requestsPerSecond !== null && $this->requestsPerSecond <= 0.0) {
            throw InvalidHttpPolicyException::nonPositiveRequestsPerSecond($this->requestsPerSecond);
        }

        if ($this->burst < 1) {
            throw InvalidHttpPolicyException::nonPositiveLimit('burst', $this->burst);
        }

        if ($this->maxConcurrent < 1) {
            throw InvalidHttpPolicyException::nonPositiveLimit('maxConcurrent', $this->maxConcurrent);
        }
    }

    public static function default(): self
    {
        return new self();
    }

    public function isRateLimited(): bool
    {
        return $this->requestsPerSecond !== null;
    }

    public function withRateLimit(?float $requestsPerSecond, int $burst = self::DEFAULT_BURST): self
    {
        return new self(
            requestsPerSecond: $requestsPerSecond,
            burst: $burst,
            maxConcurrent: $this->maxConcurrent,
            scope: $this->scope,
            failFast: $this->failFast,
        );
    }

    public function withMaxConcurrent(int $maxConcurrent): self
    {
        return new self(
            requestsPerSecond: $this->requestsPerSecond,
            burst: $this->burst,
            maxConcurrent: $maxConcurrent,
            scope: $this->scope,
            failFast: $this->failFast,
        );
    }

    public function withScope(RateLimitScope|string $scope): self
    {
        $resolvedScope = is_string($scope) ? RateLimitScope::from($scope) : $scope;

        return new self(
            requestsPerSecond: $this->requestsPerSecond,
            burst: $this->burst,
            maxConcurrent: $this->maxConcurrent,
            scope: $resolvedScope,
            failFast: $this->failFast,
        );
    }

    public function withFailFast(bool $failFast = true): self
    {
        return new self(
            requestsPerSecond: $this->requestsPerSecond,
            burst: $this->burst,
            maxConcurrent: $this->maxConcurrent,
            scope: $this->scope,
            failFast: $failFast,
        );
    }
}
