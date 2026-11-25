<?php

declare(strict_types=1);

namespace Alama\Arazzo\Expression\Policy;

use Closure;

/**
 * Immutable interpolation policy controlling how resolved runtime values are
 * stringified during template interpolation.
 */
final readonly class InterpolationPolicy
{
    private ?Closure $stringify;

    public function __construct(?callable $stringify = null)
    {
        $this->stringify = $stringify !== null ? $stringify(...) : null;
    }

    public static function default(): self
    {
        return new self();
    }

    public function withStringify(?callable $stringify): self
    {
        return new self($stringify);
    }

    public function getStringify(): ?Closure
    {
        return $this->stringify;
    }
}
