<?php

declare(strict_types=1);

namespace Alama\Arazzo\Runner\Data\Enum;

/**
 * Execution role classifying why a step was executed in a workflow run.
 */
enum StepRole: string
{
    case Standard = 'standard';
    case Dependency = 'dependency';
    case Target = 'target';
}
