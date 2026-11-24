<?php

declare(strict_types=1);

namespace Alama\Arazzo\Protocol\Rpc\Exceptions;

use InvalidArgumentException;

/**
 * @internal stays out of the advertised contract; not part of the public API surface
 */
final class ProtoSyntaxException extends InvalidArgumentException
{
    public static function expectedWord(string $what): self
    {
        return new self("Expected {$what} in .proto source.");
    }

    public static function expectedSymbol(string $symbol): self
    {
        return new self("Expected '{$symbol}' in .proto source.");
    }

    public static function expectedStringLiteral(): self
    {
        return new self('Expected a string literal in .proto source.');
    }

    public static function expectedFieldNumber(): self
    {
        return new self('Expected a field number in .proto source.');
    }

    public static function notProto3Document(): self
    {
        return new self('Source content is not a proto3 document.');
    }

    public static function unsupportedSyntax(string $version): self
    {
        return new self("Source content announces unsupported syntax '{$version}'; only proto3 is supported.");
    }
}
