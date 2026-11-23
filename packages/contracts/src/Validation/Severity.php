<?php

declare(strict_types=1);

namespace Alama\Arazzo\Contracts\Validation;

enum Severity: string
{
    case Error = 'error';
    case Warning = 'warning';
}
