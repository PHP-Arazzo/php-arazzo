<?php

declare(strict_types=1);

namespace Alama\Arazzo\Sources\Policy;

use InvalidArgumentException;

/**
 * Immutable policy controlling source fetching limits, timeouts, allowed URI
 * schemes, and reachability behavior during normalization.
 */
final readonly class FetchPolicy
{
    public const float DEFAULT_CONNECT_TIMEOUT = 2.0;

    public const float DEFAULT_TIMEOUT = 5.0;

    public const int DEFAULT_MAX_BYTES = 5_242_880; // 5 MiB

    /** @var list<string> */
    public const array DEFAULT_ALLOWED_SCHEMES = ['http', 'https', 'file'];

    public const bool DEFAULT_STRICT_REACHABILITY = false;

    /** @var list<string> */
    public array $allowedSchemes;

    /**
     * @param  list<string>  $allowedSchemes
     */
    public function __construct(
        public float $connectTimeout = self::DEFAULT_CONNECT_TIMEOUT,
        public float $timeout = self::DEFAULT_TIMEOUT,
        public int $maxBytes = self::DEFAULT_MAX_BYTES,
        array $allowedSchemes = self::DEFAULT_ALLOWED_SCHEMES,
        public bool $strictReachability = self::DEFAULT_STRICT_REACHABILITY,
    ) {
        if ($connectTimeout <= 0.0) {
            throw new InvalidArgumentException('FetchPolicy connectTimeout must be greater than 0.');
        }

        if ($timeout <= 0.0) {
            throw new InvalidArgumentException('FetchPolicy timeout must be greater than 0.');
        }

        if ($maxBytes < 1) {
            throw new InvalidArgumentException('FetchPolicy maxBytes must be at least 1.');
        }

        $this->allowedSchemes = array_values(array_unique(array_map(
            static fn (string $scheme): string => strtolower($scheme),
            $allowedSchemes,
        )));
    }

    public static function default(): self
    {
        return new self();
    }

    public function withConnectTimeout(float $seconds): self
    {
        return new self(
            connectTimeout: $seconds,
            timeout: $this->timeout,
            maxBytes: $this->maxBytes,
            allowedSchemes: $this->allowedSchemes,
            strictReachability: $this->strictReachability,
        );
    }

    public function withTimeout(float $seconds): self
    {
        return new self(
            connectTimeout: $this->connectTimeout,
            timeout: $seconds,
            maxBytes: $this->maxBytes,
            allowedSchemes: $this->allowedSchemes,
            strictReachability: $this->strictReachability,
        );
    }

    public function withMaxBytes(int $maxBytes): self
    {
        return new self(
            connectTimeout: $this->connectTimeout,
            timeout: $this->timeout,
            maxBytes: $maxBytes,
            allowedSchemes: $this->allowedSchemes,
            strictReachability: $this->strictReachability,
        );
    }

    /**
     * @param  list<string>  $schemes
     */
    public function withAllowedSchemes(array $schemes): self
    {
        return new self(
            connectTimeout: $this->connectTimeout,
            timeout: $this->timeout,
            maxBytes: $this->maxBytes,
            allowedSchemes: $schemes,
            strictReachability: $this->strictReachability,
        );
    }

    public function withStrictReachability(bool $strict = true): self
    {
        return new self(
            connectTimeout: $this->connectTimeout,
            timeout: $this->timeout,
            maxBytes: $this->maxBytes,
            allowedSchemes: $this->allowedSchemes,
            strictReachability: $strict,
        );
    }

    public function allowsScheme(string $scheme): bool
    {
        return in_array(strtolower($scheme), $this->allowedSchemes, true);
    }
}
