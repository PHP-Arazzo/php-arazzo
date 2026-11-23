<?php

declare(strict_types=1);

namespace Alama\Arazzo\Expression\Data;

final class InterpolationOptions
{
    /**
     * @var callable|null
     */
    private $stringify;

    public function __construct(
        ?callable $stringify = null,
    ) {
        $this->stringify = $stringify;
    }

    public function getStringify(): ?callable
    {
        return $this->stringify;
    }
}
