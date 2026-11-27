<?php

declare(strict_types=1);

namespace Alama\Arazzo\Protocol\Http\Policy;

use Alama\Arazzo\Protocol\Http\Exceptions\InvalidHttpPolicyException;

/**
 * Immutable value object configuring automatic idempotency key header injection
 * for mutating outbound HTTP operations.
 */
final readonly class IdempotencyPolicy
{
    public const bool DEFAULT_ENABLED = false;

    public const string DEFAULT_HEADER_NAME = 'Idempotency-Key';

    public string $headerName;

    public function __construct(
        public bool $enabled = self::DEFAULT_ENABLED,
        string $headerName = self::DEFAULT_HEADER_NAME,
    ) {
        $trimmed = trim($headerName);
        if ($trimmed === '') {
            throw InvalidHttpPolicyException::emptyHeaderName();
        }

        $this->headerName = $trimmed;
    }

    public static function default(): self
    {
        return new self();
    }

    public static function enabled(string $headerName = self::DEFAULT_HEADER_NAME): self
    {
        return new self(enabled: true, headerName: $headerName);
    }

    public function withEnabled(bool $enabled = true): self
    {
        return new self(enabled: $enabled, headerName: $this->headerName);
    }

    public function withHeaderName(string $headerName): self
    {
        return new self(enabled: $this->enabled, headerName: $headerName);
    }
}
