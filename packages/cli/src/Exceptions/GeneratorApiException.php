<?php

declare(strict_types=1);

namespace Alama\Arazzo\Cli\Exceptions;

/**
 * @internal stays out of the advertised contract; not part of the public API surface
 */
final class GeneratorApiException extends CliException
{
    public static function apiError(string $detail): self
    {
        return new self('API Error: '.$detail, '', 'cli.generator_api_error');
    }
}
