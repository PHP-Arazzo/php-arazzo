<?php

declare(strict_types=1);

namespace Alama\Arazzo\Contracts\Validation;

/**
 * Execution phase for document validation rules.
 *
 * Schema-phase rules run before semantic rules.
 */
enum ValidationPhase: string
{
    case Schema = 'schema';
    case Semantic = 'semantic';
}
