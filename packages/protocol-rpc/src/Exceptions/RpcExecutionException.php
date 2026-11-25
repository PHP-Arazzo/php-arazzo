<?php

declare(strict_types=1);

namespace Alama\Arazzo\Protocol\Rpc\Exceptions;

use Alama\Arazzo\Engine\Exceptions\ExecutionException;
use Throwable;

/**
 * @internal stays out of the advertised contract; not part of the public API surface
 */
final class RpcExecutionException extends ExecutionException
{
    public static function resolutionFailed(string $stepId, Throwable $previous): self
    {
        return new self(
            "RPC resolution failed for step '{$stepId}': ".$previous->getMessage(),
            '/',
            'execution.rpc_resolution_failed',
            $previous,
        );
    }

    public static function protocolMissing(string $stepId): self
    {
        return new self(
            "RPC step '{$stepId}' does not declare an rpcProtocol; expected one of grpc, grpc-web, twirp, connect.",
            '/',
            'execution.rpc_protocol_missing',
        );
    }

    public static function requestBodyMissing(string $stepId): self
    {
        return new self(
            "RPC step '{$stepId}' has no application/protobuf request body definition.",
            '/',
            'execution.rpc_request_body_missing',
        );
    }

    public static function targetEndpointMissing(string $stepId): self
    {
        return new self(
            "RPC step '{$stepId}' has no target endpoint.",
            '/',
            'execution.rpc_target_missing',
        );
    }
}
