<?php

declare(strict_types=1);

namespace Alama\Arazzo\Protocol\Soap\Exceptions;

use InvalidArgumentException;

/**
 * @internal stays out of the advertised contract; not part of the public API surface
 */
final class InvalidWsdlDocumentException extends InvalidArgumentException
{
    public static function invalidXml(): self
    {
        return new self('Failed to parse source content as XML.');
    }

    public static function notWsdl11(): self
    {
        return new self('Source content is not a WSDL 1.1 document.');
    }
}
