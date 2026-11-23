<?php

declare(strict_types=1);

namespace Alama\Arazzo\Contracts\Clock;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;

/**
 * Default PSR-20 system clock returning the current wall-clock instant.
 */
final readonly class SystemClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }
}
