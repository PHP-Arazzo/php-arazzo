<?php

declare(strict_types=1);

namespace Alama\Arazzo\Engine\Execution;

use Alama\Arazzo\Engine\Exceptions\TypeCastException;

/**
 * @internal stays out of the advertised contract; not part of the public API surface
 */
class TypeCaster
{
    public function asInteger(mixed $value): int
    {
        if (is_numeric($value)) {
            return (int) $value;
        }
        throw TypeCastException::toInteger();
    }

    public function asFloat(mixed $value): float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }
        throw TypeCastException::toFloat();
    }

    public function asBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value) && in_array(strtolower($value), ['true', 'false'], true)) {
            return strtolower($value) === 'true';
        }
        if (is_numeric($value)) {
            return (bool) $value;
        }
        throw TypeCastException::toBoolean();
    }

    public function asString(mixed $value): string
    {
        if (is_scalar($value)) {
            return is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
        }
        throw TypeCastException::toString();
    }

    /**
     * @return array<int|string, mixed>
     */
    public function asArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        return [$value];
    }
}
