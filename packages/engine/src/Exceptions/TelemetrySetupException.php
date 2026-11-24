<?php

declare(strict_types=1);

namespace Alama\Arazzo\Engine\Exceptions;

/**
 * @internal stays out of the advertised contract; not part of the public API surface
 */
final class TelemetrySetupException extends EngineException
{
    public static function traceFileOpenFailed(string $path): self
    {
        return new self("Could not open OTel trace file: {$path}", '', 'runtime.otel_trace_file_failed');
    }
}
