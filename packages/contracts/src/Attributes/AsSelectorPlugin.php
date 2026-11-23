<?php

declare(strict_types=1);

namespace Alama\Arazzo\Contracts\Attributes;

use Alama\Arazzo\Contracts\Spec\Enum\ExpressionType;
use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class AsSelectorPlugin
{
    public function __construct(
        public ExpressionType $type,
    ) {}
}
