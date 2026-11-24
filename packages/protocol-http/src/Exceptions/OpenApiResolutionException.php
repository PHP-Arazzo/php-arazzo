<?php

declare(strict_types=1);

namespace Alama\Arazzo\Protocol\Http\Exceptions;

use Alama\Arazzo\Engine\Exceptions\EngineException;

/**
 * @internal stays out of the advertised contract; not part of the public API surface
 */
final class OpenApiResolutionException extends EngineException
{
    public static function operationNotFound(string $operationReference, string $sourceName): self
    {
        return new self(
            "Operation '{$operationReference}' not found in source '{$sourceName}'.",
            '/',
            'http.operation_not_found',
        );
    }

    public static function sourceNotFound(string $sourceName, string $stepId): self
    {
        return new self(
            "Source '{$sourceName}' not found in document for step '{$stepId}'.",
            '/',
            'http.source_not_found',
        );
    }

    public static function documentLoadFailed(string $sourceName): self
    {
        return new self(
            "Could not load or parse OpenAPI document for source '{$sourceName}'.",
            '/',
            'http.openapi_load_failed',
        );
    }

    public static function invalidOperationPath(string $operationReference): self
    {
        return new self(
            "Invalid operationPath reference '{$operationReference}'. Expected /paths/PATH/METHOD.",
            '/',
            'http.invalid_operation_path',
        );
    }
}
