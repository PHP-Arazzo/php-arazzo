<?php

declare(strict_types=1);

namespace Alama\Arazzo\Protocol\Http\Enum;

enum RateLimitScope: string
{
    case Host = 'host';
    case Run = 'run';
}
