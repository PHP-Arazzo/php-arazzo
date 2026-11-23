<?php

declare(strict_types=1);

namespace Alama\Arazzo\Contracts\Attributes;

use Alama\Arazzo\Contracts\Spec\Enum\CriterionType;
use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class AsCriterionPlugin
{
    public function __construct(
        public CriterionType $type,
    ) {}
}
