<?php

declare(strict_types=1);

namespace Alama\Arazzo\Engine\Execution;

use cebe\openapi\spec\Reference;
use cebe\openapi\spec\Schema;

/**
 * Validates JSON Schema composition keywords (`allOf`, `anyOf`, `oneOf`) and
 * resolves `Reference` wrappers to concrete {@see Schema} instances.
 *
 * @internal stays out of the advertised contract; not part of the public API surface
 */
final class SchemaCompositionValidator
{
    /**
     * @return list<array{path: string, message: string}>
     */
    public static function validate(Schema $schema, mixed $value, string $path): array
    {
        $at = $path === '' ? '/' : $path;

        return [
            ...self::validateAllOf($schema->allOf ?? [], $value, $path),
            ...self::validateAnyOf($schema->anyOf ?? [], $value, $path, $at),
            ...self::validateOneOf($schema->oneOf ?? [], $value, $path, $at),
        ];
    }

    public static function resolveSchema(mixed $schemaOrRef): ?Schema
    {
        if ($schemaOrRef instanceof Reference) {
            $schemaOrRef = $schemaOrRef->resolve();
        }

        return $schemaOrRef instanceof Schema ? $schemaOrRef : null;
    }

    /**
     * @param  array<array-key, mixed>  $schemas
     * @return list<array{path: string, message: string}>
     */
    private static function validateAllOf(array $schemas, mixed $value, string $path): array
    {
        $violations = [];
        foreach ($schemas as $subSchema) {
            $resolved = self::resolveSchema($subSchema);
            if ($resolved !== null) {
                $violations = [...$violations, ...SchemaValidator::validate($resolved, $value, $path)];
            }
        }

        return $violations;
    }

    /**
     * @param  array<array-key, mixed>  $schemas
     * @return list<array{path: string, message: string}>
     */
    private static function validateAnyOf(array $schemas, mixed $value, string $path, string $at): array
    {
        if ($schemas === []) {
            return [];
        }

        foreach ($schemas as $subSchema) {
            $resolved = self::resolveSchema($subSchema);
            if ($resolved !== null && SchemaValidator::validate($resolved, $value, $path) === []) {
                return [];
            }
        }

        return [['path' => $at, 'message' => 'value does not match any of the required schemas (anyOf)']];
    }

    /**
     * @param  array<array-key, mixed>  $schemas
     * @return list<array{path: string, message: string}>
     */
    private static function validateOneOf(array $schemas, mixed $value, string $path, string $at): array
    {
        if ($schemas === []) {
            return [];
        }

        $passes = 0;
        foreach ($schemas as $subSchema) {
            $resolved = self::resolveSchema($subSchema);
            if ($resolved !== null && SchemaValidator::validate($resolved, $value, $path) === []) {
                $passes++;
            }
        }

        if ($passes !== 1) {
            return [['path' => $at, 'message' => "value matches {$passes} schemas instead of exactly 1 (oneOf)"]];
        }

        return [];
    }
}
