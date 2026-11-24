<?php

declare(strict_types=1);

namespace Alama\Arazzo\Protocol\Rpc\Exceptions;

use Alama\Arazzo\Engine\Exceptions\EngineException;

/**
 * @internal stays out of the advertised contract; not part of the public API surface
 */
final class ProtobufResolutionException extends EngineException
{
    public static function notProtobufStep(string $stepId): self
    {
        return new self(
            "Step '{$stepId}' does not reference a protobuf source.",
            '/',
            'rpc.not_protobuf_step',
        );
    }

    public static function sourceNotFound(string $sourceName): self
    {
        return new self(
            "Source '{$sourceName}' not found in the document.",
            '/',
            'rpc.source_not_found',
        );
    }

    public static function methodNotFound(string $operationReference, string $sourceName): self
    {
        return new self(
            "RPC method '{$operationReference}' not found in source '{$sourceName}'.",
            '/',
            'rpc.method_not_found',
        );
    }
}
