<?php

declare(strict_types=1);

namespace Alama\Arazzo\Protocol\Rpc\Exceptions;

use Alama\Arazzo\Engine\Exceptions\EngineException;

/**
 * @internal stays out of the advertised contract; not part of the public API surface
 */
final class RpcCodecException extends EngineException
{
    public static function unsupportedWireType(int $wire): self
    {
        return new self(
            "Unsupported protobuf wire type {$wire} while decoding an RPC response.",
            '/',
            'rpc.unsupported_wire_type',
        );
    }

    public static function nonScalarFieldValue(string $name): self
    {
        return new self(
            "Non-scalar value for field '{$name}' cannot be wire-encoded.",
            '/',
            'rpc.non_scalar_field_value',
        );
    }

    public static function unsupportedFieldTypeFor(string $type, string $name): self
    {
        return new self(
            "Unsupported protobuf field type '{$type}' for field '{$name}'.",
            '/',
            'rpc.unsupported_field_type',
        );
    }

    public static function unsupportedFieldType(string $type): self
    {
        return new self(
            "Unsupported protobuf field type '{$type}'.",
            '/',
            'rpc.unsupported_field_type',
        );
    }
}
