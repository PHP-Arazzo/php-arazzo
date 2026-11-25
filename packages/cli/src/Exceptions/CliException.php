<?php

declare(strict_types=1);

namespace Alama\Arazzo\Cli\Exceptions;

use Alama\Arazzo\Engine\Exceptions\EngineException;

/**
 * Base exception for failures raised by the CLI package.
 */
abstract class CliException extends EngineException {}
