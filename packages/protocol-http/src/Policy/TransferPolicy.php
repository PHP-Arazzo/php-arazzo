<?php

declare(strict_types=1);

namespace Alama\Arazzo\Protocol\Http\Policy;

use Alama\Arazzo\Protocol\Http\Exceptions\InvalidHttpPolicyException;

/**
 * Immutable wire-transfer bounds value object governing maximum response
 * payload size and socket connect timeout (in seconds).
 */
final readonly class TransferPolicy
{
    public const int DEFAULT_MAX_RESPONSE_BYTES = 10_485_760; // 10 MiB

    public const float DEFAULT_CONNECT_TIMEOUT = 10.0;

    public function __construct(
        public int $maxResponseBytes = self::DEFAULT_MAX_RESPONSE_BYTES,
        public float $connectTimeout = self::DEFAULT_CONNECT_TIMEOUT,
    ) {
        if ($this->maxResponseBytes < 1) {
            throw InvalidHttpPolicyException::nonPositiveLimit('maxResponseBytes', $this->maxResponseBytes);
        }

        if ($this->connectTimeout <= 0.0) {
            throw InvalidHttpPolicyException::nonPositiveLimit('connectTimeout', $this->connectTimeout);
        }
    }

    public static function default(): self
    {
        return new self();
    }

    public function exceedsResponseCap(int $byteCount): bool
    {
        return $byteCount > $this->maxResponseBytes;
    }

    public function withMaxResponseBytes(int $maxResponseBytes): self
    {
        return new self(
            maxResponseBytes: $maxResponseBytes,
            connectTimeout: $this->connectTimeout,
        );
    }

    public function withConnectTimeout(float $seconds): self
    {
        return new self(
            maxResponseBytes: $this->maxResponseBytes,
            connectTimeout: $seconds,
        );
    }
}
