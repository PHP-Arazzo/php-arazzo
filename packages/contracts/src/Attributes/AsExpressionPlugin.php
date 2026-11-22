<?php

declare(strict_types=1);

namespace Alama\Arazzo\Contracts\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class AsExpressionPlugin
{
    public function __construct(
        public int $priority = 0,
    ) {}
}
