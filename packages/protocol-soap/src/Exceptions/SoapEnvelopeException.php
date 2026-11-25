<?php

declare(strict_types=1);

namespace Alama\Arazzo\Protocol\Soap\Exceptions;

use Alama\Arazzo\Engine\Exceptions\EngineException;

/**
 * @internal stays out of the advertised contract; not part of the public API surface
 */
final class SoapEnvelopeException extends EngineException
{
    public static function elementCreationFailed(): self
    {
        return new self('Failed to create SOAP envelope element.', '/', 'soap.envelope_element_failed');
    }
}
