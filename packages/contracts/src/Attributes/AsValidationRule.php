<?php

declare(strict_types=1);

namespace Alama\Arazzo\Contracts\Attributes;

use Attribute;

/**
 * Marks a ValidationRuleInterface implementation for declarative registration
 * and auto-discovery via Composer's extra.arazzo.plugins manifest.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class AsValidationRule
{
    public function __construct(
        public int $priority = 0,
    ) {}
}
