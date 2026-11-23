<?php

declare(strict_types=1);

namespace Alama\Arazzo\Runner\Exceptions;

use Alama\Arazzo\Contracts\Support\Exceptions\ArazzoException;

/**
 * Base exception for all workflow execution failures raised by the runner package.
 */
abstract class RunnerException extends ArazzoException {}
