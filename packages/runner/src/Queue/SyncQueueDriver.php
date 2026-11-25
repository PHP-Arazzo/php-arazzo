<?php

declare(strict_types=1);

namespace Alama\Arazzo\Runner\Queue;

use Alama\Arazzo\Contracts\Interfaces\QueueDriverInterface;

/**
 * In-process FIFO queue driver for local runs, CLI draining, and tests.
 */
final class SyncQueueDriver implements QueueDriverInterface
{
    /** @var list<array{job: object, delaySeconds: int}> */
    public array $dispatched = [];

    public function dispatch(object $job, int $delaySeconds = 0): void
    {
        $this->dispatched[] = [
            'job' => $job,
            'delaySeconds' => $delaySeconds,
        ];
    }
}
