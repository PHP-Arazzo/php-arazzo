<?php

declare(strict_types=1);

namespace Alama\Arazzo\Protocol\Http\Policy;

use Alama\Arazzo\Engine\Execution\IdempotencyKeyInjector;

/**
 * Immutable policy configuring default HTTP step execution behavior
 * (response schema validation strictness and idempotency key injection).
 */
final readonly class HttpPolicy
{
    public const bool DEFAULT_STRICT_VALIDATION = true;

    public function __construct(
        public bool $strictValidation = self::DEFAULT_STRICT_VALIDATION,
        public ?IdempotencyKeyInjector $idempotencyInjector = null,
    ) {}

    public static function default(): self
    {
        return new self();
    }

    public function withStrictValidation(bool $strictValidation = true): self
    {
        return new self(
            strictValidation: $strictValidation,
            idempotencyInjector: $this->idempotencyInjector,
        );
    }

    public function withIdempotencyInjector(?IdempotencyKeyInjector $injector): self
    {
        return new self(
            strictValidation: $this->strictValidation,
            idempotencyInjector: $injector,
        );
    }
}
