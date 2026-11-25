<?php

declare(strict_types=1);

namespace Alama\Arazzo\Engine\Execution;

use cebe\openapi\spec\Schema;
use DateTime;
use DateTimeInterface;

/**
 * Validates JSON Schema string and numeric constraints (`minLength`, `maxLength`,
 * `pattern`, `format`, `minimum`, `maximum`, `multipleOf`).
 *
 * @internal stays out of the advertised contract; not part of the public API surface
 */
final class ScalarSchemaValidator
{
    public static function matchesType(string $type, mixed $value): bool
    {
        return match ($type) {
            'integer' => is_int($value),
            'number' => is_int($value) || is_float($value),
            'string' => is_string($value),
            'boolean' => is_bool($value),
            'array' => is_array($value) && array_is_list($value),
            // json_decode(..., true) can't tell an empty object `{}` from an empty array
            // `[]` -- both decode to `[]`, so an empty array is accepted as either shape.
            'object' => is_array($value) && (!array_is_list($value) || $value === []),
            default => true,
        };
    }

    /**
     * @return list<array{path: string, message: string}>
     */
    public static function validateString(Schema $schema, string $value, string $path): array
    {
        $at = $path === '' ? '/' : $path;
        $violations = [];

        if (($schema->minLength ?? null) !== null && mb_strlen($value) < $schema->minLength) {
            $violations[] = ['path' => $at, 'message' => "expected at least {$schema->minLength} characters, got ".mb_strlen($value)];
        }
        if (($schema->maxLength ?? null) !== null && mb_strlen($value) > $schema->maxLength) {
            $violations[] = ['path' => $at, 'message' => "expected at most {$schema->maxLength} characters, got ".mb_strlen($value)];
        }
        if (($schema->pattern ?? null) !== null && @preg_match('/'.str_replace('/', '\/', $schema->pattern).'/u', $value) !== 1) {
            $violations[] = ['path' => $at, 'message' => "does not match pattern '{$schema->pattern}'"];
        }
        if (($schema->format ?? null) !== null && !self::matchesFormat($schema->format, $value)) {
            $violations[] = ['path' => $at, 'message' => "does not match format '{$schema->format}'"];
        }

        return $violations;
    }

    /**
     * @return list<array{path: string, message: string}>
     */
    public static function validateNumber(Schema $schema, int|float $value, string $path): array
    {
        $at = $path === '' ? '/' : $path;

        return [
            ...self::checkMinimum($schema, $value, $at),
            ...self::checkMaximum($schema, $value, $at),
            ...self::checkMultipleOf($schema, $value, $at),
        ];
    }

    /**
     * @return list<array{path: string, message: string}>
     */
    private static function checkMinimum(Schema $schema, int|float $value, string $at): array
    {
        $minimum = $schema->minimum ?? null;
        if ($minimum === null) {
            return [];
        }

        $exclusive = $schema->exclusiveMinimum === true;
        if ($exclusive ? $value <= $minimum : $value < $minimum) {
            $bound = $exclusive ? 'greater than' : 'at least';

            return [['path' => $at, 'message' => "value must be {$bound} {$minimum}"]];
        }

        return [];
    }

    /**
     * @return list<array{path: string, message: string}>
     */
    private static function checkMaximum(Schema $schema, int|float $value, string $at): array
    {
        $maximum = $schema->maximum ?? null;
        if ($maximum === null) {
            return [];
        }

        $exclusive = $schema->exclusiveMaximum === true;
        if ($exclusive ? $value >= $maximum : $value > $maximum) {
            $bound = $exclusive ? 'less than' : 'at most';

            return [['path' => $at, 'message' => "value must be {$bound} {$maximum}"]];
        }

        return [];
    }

    /**
     * @return list<array{path: string, message: string}>
     */
    private static function checkMultipleOf(Schema $schema, int|float $value, string $at): array
    {
        $multipleOf = $schema->multipleOf ?? null;
        if ($multipleOf === null || (float) $multipleOf === 0.0) {
            return [];
        }

        $remainder = fmod((float) $value, (float) $multipleOf);
        $nearZero = abs($remainder) < 1e-9;
        $nearDivisor = abs(abs($remainder) - abs((float) $multipleOf)) < 1e-9;
        if (!$nearZero && !$nearDivisor) {
            return [['path' => $at, 'message' => "value must be a multiple of {$multipleOf}"]];
        }

        return [];
    }

    private static function matchesFormat(string $format, string $value): bool
    {
        return match ($format) {
            'date' => self::isValidDateTime($value, 'Y-m-d'),
            'date-time' => self::isValidDateTime($value, DateTimeInterface::RFC3339_EXTENDED)
                || self::isValidDateTime($value, DateTimeInterface::RFC3339),
            'email' => filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
            'uuid' => preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1,
            'uri' => filter_var($value, FILTER_VALIDATE_URL) !== false,
            'ipv4' => filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false,
            'ipv6' => filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false,
            default => true,
        };
    }

    private static function isValidDateTime(string $value, string $format): bool
    {
        $date = DateTime::createFromFormat('!'.$format, $value);

        return $date !== false && $date->format($format) === $value;
    }
}
