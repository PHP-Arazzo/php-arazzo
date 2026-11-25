<?php

declare(strict_types=1);

namespace Alama\Arazzo\Engine\Exceptions;

/**
 * @internal stays out of the advertised contract; not part of the public API surface
 */
final class StateStoreException extends EngineException
{
    public static function directoryCreationFailed(string $dir): self
    {
        return new self("Could not create state directory: {$dir}", '', 'runtime.state_dir_failed');
    }

    public static function persistFailed(string $executionId): self
    {
        return new self("Could not persist execution state: {$executionId}", '', 'runtime.state_persist_failed');
    }

    public static function deleteFailed(string $executionId): self
    {
        return new self("Could not delete execution state: {$executionId}", '', 'runtime.state_delete_failed');
    }
}
