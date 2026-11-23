<?php

declare(strict_types=1);

namespace Alama\Arazzo\Contracts\Policy;

/**
 * Per-source transport and validation overrides inside a {@see SourcePolicy}.
 */
final readonly class SourceTransportPolicy
{
    public function __construct(
        public ?float $timeoutSeconds = null,
        public ?bool $strictValidation = null,
    ) {}

    public static function default(): self
    {
        return new self();
    }

    public function withTimeoutSeconds(?float $timeoutSeconds): self
    {
        return new self($timeoutSeconds, $this->strictValidation);
    }

    public function withStrictValidation(?bool $strictValidation): self
    {
        return new self($this->timeoutSeconds, $strictValidation);
    }
}
