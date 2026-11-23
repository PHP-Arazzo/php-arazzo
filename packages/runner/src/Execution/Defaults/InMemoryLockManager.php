<?php

declare(strict_types=1);

namespace Alama\Arazzo\Runner\Execution\Defaults;

use Alama\Arazzo\Contracts\Interfaces\LockManagerInterface;
use Alama\Arazzo\Runner\AsyncRunner;

/**
 * Default in-process lock manager used by {@see AsyncRunner::create()}.
 *
 * @internal stays out of the advertised contract; not part of the public API surface
 */
final class InMemoryLockManager implements LockManagerInterface
{
    public function acquire(string $key, int $ttlSeconds, callable $callback): mixed
    {
        return $callback();
    }

    public function tryAcquire(string $key, int $ttlSeconds): bool
    {
        return true;
    }

    public function release(string $key): void {}
}
