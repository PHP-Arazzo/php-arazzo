<?php

declare(strict_types=1);

namespace Alama\Arazzo\Contracts\Exceptions;

use Throwable;

/**
 * Marker interface for all execution-phase exceptions across the Arazzo runtime.
 */
interface ArazzoExecutionExceptionInterface extends Throwable {}
