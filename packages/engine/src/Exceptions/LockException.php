<?php

declare(strict_types=1);

namespace Alama\Arazzo\Engine\Exceptions;

/**
 * @internal stays out of the advertised contract; not part of the public API surface
 */
final class LockException extends EngineException
{
    public static function lockFileCreationFailed(string $lockFile): self
    {
        return new self("Could not create lock file: {$lockFile}", '', 'runtime.lock_file_failed');
    }

    public static function lockAcquisitionFailed(string $key): self
    {
        return new self("Could not acquire lock for key: {$key}", '', 'runtime.lock_acquire_failed');
    }
}
