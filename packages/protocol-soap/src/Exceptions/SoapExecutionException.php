<?php

declare(strict_types=1);

namespace Alama\Arazzo\Protocol\Soap\Exceptions;

use Alama\Arazzo\Engine\Exceptions\ExecutionException;
use Throwable;

/**
 * @internal stays out of the advertised contract; not part of the public API surface
 */
final class SoapExecutionException extends ExecutionException
{
    public static function resolutionFailed(string $stepId, Throwable $previous): self
    {
        return new self(
            "SOAP resolution failed for step '{$stepId}': ".$previous->getMessage(),
            '/',
            'execution.soap_resolution_failed',
            $previous,
        );
    }

    public static function requestBodyMissing(string $stepId): self
    {
        return new self(
            "SOAP step '{$stepId}' has no application/xml request body definition.",
            '/',
            'execution.soap_request_body_missing',
        );
    }

    public static function namespaceMissing(string $stepId): self
    {
        return new self(
            "SOAP step '{$stepId}' has no request namespace (WSDL targetNamespace missing).",
            '/',
            'execution.soap_namespace_missing',
        );
    }

    public static function targetEndpointMissing(string $stepId): self
    {
        return new self(
            "SOAP step '{$stepId}' has no target endpoint.",
            '/',
            'execution.soap_target_missing',
        );
    }
}
