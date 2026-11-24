<?php

declare(strict_types=1);

namespace Alama\Arazzo\Engine\Exceptions;

use Alama\Arazzo\Contracts\Support\Exceptions\ArazzoException;

/**
 * Base exception for all runtime and execution failures raised by the engine package.
 */
abstract class EngineException extends ArazzoException {}
