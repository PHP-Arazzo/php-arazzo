<?php

declare(strict_types=1);

namespace Alama\Arazzo\Protocol\Http\Policy;

use Alama\Arazzo\Protocol\Http\Exceptions\InvalidHttpPolicyException;

/**
 * Pure immutable aggregate policy root configuring HTTP step execution behavior:
 * schema validation strictness, idempotency injection, rate limiting, outbound
 * safety bounds, and optional single-level per-source overrides.
 */
final readonly class HttpPolicy
{
    public const bool DEFAULT_STRICT_VALIDATION = true;

    /** @var array<string, self> */
    public array $sourceOverrides;

    /**
     * @param  array<string, self>  $sourceOverrides  Keyed by `sourceDescriptions[*].name`
     */
    public function __construct(
        public bool $strictValidation = self::DEFAULT_STRICT_VALIDATION,
        public IdempotencyPolicy $idempotency = new IdempotencyPolicy(),
        public RateLimitPolicy $rateLimit = new RateLimitPolicy(),
        public OutboundSafetyPolicy $safety = new OutboundSafetyPolicy(),
        array $sourceOverrides = [],
    ) {
        $validated = [];
        foreach ($sourceOverrides as $sourceName => $override) {
            $trimmed = trim($sourceName);
            if ($trimmed === '') {
                throw InvalidHttpPolicyException::emptySourceName();
            }

            if ($override->sourceOverrides !== []) {
                throw InvalidHttpPolicyException::nestedSourceOverrides($trimmed);
            }

            $validated[$trimmed] = $override;
        }

        $this->sourceOverrides = $validated;
    }

    public static function default(): self
    {
        return new self();
    }

    public function withStrictValidation(bool $strictValidation = true): self
    {
        return new self(
            strictValidation: $strictValidation,
            idempotency: $this->idempotency,
            rateLimit: $this->rateLimit,
            safety: $this->safety,
            sourceOverrides: $this->sourceOverrides,
        );
    }

    public function withIdempotency(IdempotencyPolicy $idempotency): self
    {
        return new self(
            strictValidation: $this->strictValidation,
            idempotency: $idempotency,
            rateLimit: $this->rateLimit,
            safety: $this->safety,
            sourceOverrides: $this->sourceOverrides,
        );
    }

    public function withRateLimit(RateLimitPolicy $rateLimit): self
    {
        return new self(
            strictValidation: $this->strictValidation,
            idempotency: $this->idempotency,
            rateLimit: $rateLimit,
            safety: $this->safety,
            sourceOverrides: $this->sourceOverrides,
        );
    }

    public function withSafety(OutboundSafetyPolicy $safety): self
    {
        return new self(
            strictValidation: $this->strictValidation,
            idempotency: $this->idempotency,
            rateLimit: $this->rateLimit,
            safety: $safety,
            sourceOverrides: $this->sourceOverrides,
        );
    }

    public function withSourceOverride(string $sourceName, self $override): self
    {
        $overrides = $this->sourceOverrides;
        $overrides[$sourceName] = $override;

        return new self(
            strictValidation: $this->strictValidation,
            idempotency: $this->idempotency,
            rateLimit: $this->rateLimit,
            safety: $this->safety,
            sourceOverrides: $overrides,
        );
    }

    public function withoutSourceOverride(string $sourceName): self
    {
        $overrides = $this->sourceOverrides;
        unset($overrides[trim($sourceName)]);

        return new self(
            strictValidation: $this->strictValidation,
            idempotency: $this->idempotency,
            rateLimit: $this->rateLimit,
            safety: $this->safety,
            sourceOverrides: $overrides,
        );
    }

    public function hasSourceOverride(string $sourceName): bool
    {
        return isset($this->sourceOverrides[trim($sourceName)]);
    }

    public function forSource(?string $sourceName): self
    {
        if ($sourceName === null) {
            return $this;
        }

        return $this->sourceOverrides[trim($sourceName)] ?? $this;
    }
}
