<?php

declare(strict_types=1);

namespace Alama\Arazzo\Engine\Execution;

use cebe\openapi\spec\Schema;

/**
 * Guards against a cebe attribute that was never set are load-bearing, not dead
 * code: its `@property` docblock claims every attribute is non-nullable, but
 * `SpecBaseObject::__get()` falls back to `attributeDefaults()`, which yields
 * `null` for an unset attribute. PHPStan believes the docblock and reports the
 * guard as `notIdentical.alwaysTrue`, so the reads below go through
 * `($schema->x ?? null)` -- the null-safe read of a docblock that lies (#223).
 *
 * @internal stays out of the advertised contract; not part of the public API surface
 */
final class SchemaValidator
{
    /**
     * @return list<array{path: string, message: string}>
     */
    public static function validate(Schema $schema, mixed $value, string $path = ''): array
    {
        $at = $path === '' ? '/' : $path;

        $composed = SchemaCompositionValidator::validate($schema, $value, $path);
        if ($composed !== []) {
            return $composed;
        }

        if ($value === null) {
            return self::validateNull($schema, $at);
        }

        return [
            ...self::validateTypeAndEnum($schema, $value, $at),
            ...self::validateValueShape($schema, $value, $path),
        ];
    }

    /**
     * @return list<array{path: string, message: string}>
     */
    private static function validateNull(Schema $schema, string $at): array
    {
        if ($schema->nullable === true || ($schema->type ?? null) === null) {
            return [];
        }

        return [['path' => $at, 'message' => 'must not be null']];
    }

    /**
     * @return list<array{path: string, message: string}>
     */
    private static function validateTypeAndEnum(Schema $schema, mixed $value, string $at): array
    {
        $violations = [];

        if (($schema->type ?? null) !== null && !ScalarSchemaValidator::matchesType($schema->type, $value)) {
            $violations[] = ['path' => $at, 'message' => "expected type '{$schema->type}', got ".get_debug_type($value)];
        }

        if (($schema->enum ?? null) !== null && $schema->enum !== [] && !in_array($value, $schema->enum, true)) {
            $violations[] = ['path' => $at, 'message' => 'value is not one of the allowed enum values'];
        }

        return $violations;
    }

    /**
     * @return list<array{path: string, message: string}>
     */
    private static function validateValueShape(Schema $schema, mixed $value, string $path): array
    {
        if (is_array($value)) {
            return self::validateCollection($schema, $value, $path);
        }

        if (is_string($value)) {
            return ScalarSchemaValidator::validateString($schema, $value, $path);
        }

        if (is_int($value) || is_float($value)) {
            return ScalarSchemaValidator::validateNumber($schema, $value, $path);
        }

        return [];
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return list<array{path: string, message: string}>
     */
    private static function validateCollection(Schema $schema, array $value, string $path): array
    {
        $violations = [];

        if ($value === [] || !array_is_list($value)) {
            $violations = [...$violations, ...self::validateObject($schema, $value, $path)];
        }

        if ($value === [] || array_is_list($value)) {
            /** @var list<mixed> $value */
            $violations = [...$violations, ...self::validateArray($schema, $value, $path)];
        }

        return $violations;
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return list<array{path: string, message: string}>
     */
    private static function validateObject(Schema $schema, array $value, string $path): array
    {
        $at = $path === '' ? '/' : $path;

        return [
            ...self::checkPropertyCount($schema, $value, $at),
            ...self::checkRequiredProperties($schema, $value, $path),
            ...self::checkObjectProperties($schema, $value, $path),
        ];
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return list<array{path: string, message: string}>
     */
    private static function checkPropertyCount(Schema $schema, array $value, string $at): array
    {
        $violations = [];
        $count = count($value);

        if (($schema->minProperties ?? null) !== null && $count < $schema->minProperties) {
            $violations[] = ['path' => $at, 'message' => "expected at least {$schema->minProperties} properties, got {$count}"];
        }
        if (($schema->maxProperties ?? null) !== null && $count > $schema->maxProperties) {
            $violations[] = ['path' => $at, 'message' => "expected at most {$schema->maxProperties} properties, got {$count}"];
        }

        return $violations;
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return list<array{path: string, message: string}>
     */
    private static function checkRequiredProperties(Schema $schema, array $value, string $path): array
    {
        $violations = [];
        foreach (($schema->required ?? []) as $requiredName) {
            if (!array_key_exists($requiredName, $value)) {
                $violations[] = [
                    'path' => $path.'/'.$requiredName,
                    'message' => "missing required property '{$requiredName}'",
                ];
            }
        }

        return $violations;
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return list<array{path: string, message: string}>
     */
    private static function checkObjectProperties(Schema $schema, array $value, string $path): array
    {
        $violations = [];
        foreach (($schema->properties ?? []) as $name => $propSchema) {
            if (!array_key_exists($name, $value)) {
                continue;
            }
            $resolved = SchemaCompositionValidator::resolveSchema($propSchema);
            if ($resolved !== null) {
                $violations = [...$violations, ...self::validate($resolved, $value[$name], $path.'/'.$name)];
            }
        }

        return $violations;
    }

    /**
     * @param  list<mixed>  $value
     * @return list<array{path: string, message: string}>
     */
    private static function validateArray(Schema $schema, array $value, string $path): array
    {
        $at = $path === '' ? '/' : $path;
        $violations = [];

        if (($schema->minItems ?? null) !== null && count($value) < $schema->minItems) {
            $violations[] = ['path' => $at, 'message' => "expected at least {$schema->minItems} items, got ".count($value)];
        }
        if (($schema->maxItems ?? null) !== null && count($value) > $schema->maxItems) {
            $violations[] = ['path' => $at, 'message' => "expected at most {$schema->maxItems} items, got ".count($value)];
        }
        if ($schema->uniqueItems === true && count($value) !== count(array_unique($value, SORT_REGULAR))) {
            $violations[] = ['path' => $at, 'message' => 'items must be unique'];
        }

        $itemSchema = SchemaCompositionValidator::resolveSchema($schema->items);
        if ($itemSchema !== null) {
            foreach ($value as $index => $item) {
                $violations = [...$violations, ...self::validate($itemSchema, $item, $path.'/'.$index)];
            }
        }

        return $violations;
    }
}
