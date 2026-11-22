<?php

declare(strict_types=1);

namespace Alama\Arazzo\Contracts\Attributes;

use Alama\Arazzo\Contracts\Spec\Enum\SourceType;
use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class AsSourceNormalizer
{
    public function __construct(
        public SourceType $type,
        public int $priority = 0,
    ) {}
}
