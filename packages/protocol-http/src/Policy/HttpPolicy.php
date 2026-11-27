<?php

declare(strict_types=1);

namespace Alama\Arazzo\Protocol\Http\Policy;

use Alama\Arazzo\Engine\Execution\IdempotencyKeyInjector;
use Alama\Arazzo\Protocol\Http\Enum\RateLimitScope;
use Alama\Arazzo\Protocol\Http\Execution\OutboundRateLimiter;

/**
 * Immutable policy configuring HTTP step execution behavior:
 * response schema validation strictness, idempotency key injection,
 * outbound rate limiting, and concurrency capping.
 *
 * @property-read ?float $requestsPerSecond
 * @property-read int $burst
 * @property-read int $maxConcurrent
 * @property-read RateLimitScope $scope
 * @property-read bool $failFast
 */
final readonly class HttpPolicy
{
    public const bool DEFAULT_STRICT_VALIDATION = true;

    public const bool DEFAULT_RESPECT_RETRY_AFTER = true;

    public function __construct(
        public bool $strictValidation = self::DEFAULT_STRICT_VALIDATION,
        public ?IdempotencyKeyInjector $idempotencyInjector = null,
        public OutboundRequestPolicy $rateLimit = new OutboundRequestPolicy(),
        public bool $respectRetryAfter = self::DEFAULT_RESPECT_RETRY_AFTER,
        public ?OutboundRateLimiter $rateLimiter = null,
    ) {}

    public function __get(string $name): mixed
    {
        return match ($name) {
            'requestsPerSecond' => $this->rateLimit->requestsPerSecond,
            'burst' => $this->rateLimit->burst,
            'maxConcurrent' => $this->rateLimit->maxConcurrent,
            'scope' => $this->rateLimit->scope,
            'failFast' => $this->rateLimit->failFast,
            default => null,
        };
    }

    public function __isset(string $name): bool
    {
        return in_array($name, ['requestsPerSecond', 'burst', 'maxConcurrent', 'scope', 'failFast'], true);
    }

    public static function default(): self
    {
        return new self();
    }

    public function requestsPerSecond(): ?float
    {
        return $this->rateLimit->requestsPerSecond;
    }

    public function burst(): int
    {
        return $this->rateLimit->burst;
    }

    public function maxConcurrent(): int
    {
        return $this->rateLimit->maxConcurrent;
    }

    public function scope(): RateLimitScope
    {
        return $this->rateLimit->scope;
    }

    public function failFast(): bool
    {
        return $this->rateLimit->failFast;
    }

    public function withStrictValidation(bool $strictValidation = true): self
    {
        return new self(
            strictValidation: $strictValidation,
            idempotencyInjector: $this->idempotencyInjector,
            rateLimit: $this->rateLimit,
            respectRetryAfter: $this->respectRetryAfter,
            rateLimiter: $this->rateLimiter,
        );
    }

    public function withIdempotencyInjector(?IdempotencyKeyInjector $injector): self
    {
        return new self(
            strictValidation: $this->strictValidation,
            idempotencyInjector: $injector,
            rateLimit: $this->rateLimit,
            respectRetryAfter: $this->respectRetryAfter,
            rateLimiter: $this->rateLimiter,
        );
    }

    public function withRateLimit(?float $requestsPerSecond, int $burst = OutboundRequestPolicy::DEFAULT_BURST): self
    {
        return new self(
            strictValidation: $this->strictValidation,
            idempotencyInjector: $this->idempotencyInjector,
            rateLimit: $this->rateLimit->withRateLimit($requestsPerSecond, $burst),
            respectRetryAfter: $this->respectRetryAfter,
            rateLimiter: $this->rateLimiter,
        );
    }

    public function withMaxConcurrent(int $maxConcurrent): self
    {
        return new self(
            strictValidation: $this->strictValidation,
            idempotencyInjector: $this->idempotencyInjector,
            rateLimit: $this->rateLimit->withMaxConcurrent($maxConcurrent),
            respectRetryAfter: $this->respectRetryAfter,
            rateLimiter: $this->rateLimiter,
        );
    }

    public function withScope(RateLimitScope|string $scope): self
    {
        return new self(
            strictValidation: $this->strictValidation,
            idempotencyInjector: $this->idempotencyInjector,
            rateLimit: $this->rateLimit->withScope($scope),
            respectRetryAfter: $this->respectRetryAfter,
            rateLimiter: $this->rateLimiter,
        );
    }

    public function withRespectRetryAfter(bool $respectRetryAfter = true): self
    {
        return new self(
            strictValidation: $this->strictValidation,
            idempotencyInjector: $this->idempotencyInjector,
            rateLimit: $this->rateLimit,
            respectRetryAfter: $respectRetryAfter,
            rateLimiter: $this->rateLimiter,
        );
    }

    public function withFailFast(bool $failFast = true): self
    {
        return new self(
            strictValidation: $this->strictValidation,
            idempotencyInjector: $this->idempotencyInjector,
            rateLimit: $this->rateLimit->withFailFast($failFast),
            respectRetryAfter: $this->respectRetryAfter,
            rateLimiter: $this->rateLimiter,
        );
    }

    public function withRateLimiter(?OutboundRateLimiter $rateLimiter): self
    {
        return new self(
            strictValidation: $this->strictValidation,
            idempotencyInjector: $this->idempotencyInjector,
            rateLimit: $this->rateLimit,
            respectRetryAfter: $this->respectRetryAfter,
            rateLimiter: $rateLimiter,
        );
    }
}
