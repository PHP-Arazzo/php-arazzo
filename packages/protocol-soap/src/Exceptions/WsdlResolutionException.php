<?php

declare(strict_types=1);

namespace Alama\Arazzo\Protocol\Soap\Exceptions;

use Alama\Arazzo\Engine\Exceptions\EngineException;

/**
 * @internal stays out of the advertised contract; not part of the public API surface
 */
final class WsdlResolutionException extends EngineException
{
    public static function notWsdlStep(string $stepId): self
    {
        return new self(
            "Step '{$stepId}' does not reference a WSDL source.",
            '/',
            'soap.not_wsdl_step',
        );
    }

    public static function sourceNotFound(string $sourceName): self
    {
        return new self(
            "Source '{$sourceName}' not found in the document.",
            '/',
            'soap.source_not_found',
        );
    }

    public static function operationNotFound(string $operationReference, string $sourceName): self
    {
        return new self(
            "WSDL operation '{$operationReference}' not found in source '{$sourceName}'.",
            '/',
            'soap.operation_not_found',
        );
    }
}
