<?php

declare(strict_types=1);

namespace Alama\Arazzo\Contracts\Attributes;

use Alama\Arazzo\Contracts\Validation\ValidationPhase;
use Attribute;

/**
 * Marks a ValidationRuleInterface implementation for declarative registration
 * and auto-discovery via Composer's extra.arazzo.plugins manifest.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class AsValidationRulePlugin
{
    public function __construct(
        public ValidationPhase $phase = ValidationPhase::Semantic,
    ) {}
}
