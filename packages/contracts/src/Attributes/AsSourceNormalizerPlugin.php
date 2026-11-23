<?php

declare(strict_types=1);

namespace Alama\Arazzo\Contracts\Attributes;

use Alama\Arazzo\Contracts\Spec\Enum\SourceType;
use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class AsSourceNormalizerPlugin
{
    public function __construct(
        public SourceType $type,
    ) {}
}
