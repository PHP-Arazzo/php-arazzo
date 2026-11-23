<?php

declare(strict_types=1);

namespace Alama\Arazzo\Tests\Engine\Execution;

use Alama\Arazzo\Engine\Execution\SchemaValidator;
use cebe\openapi\ReferenceContext;
use cebe\openapi\spec\OpenApi;
use cebe\openapi\spec\Reference;
use cebe\openapi\spec\Schema;

it('validates a schema that declares no composition keywords', function (): void {
    // `Schema::attributeDefaults()` hands back null for allOf/anyOf/oneOf and
    // `SpecBaseObject::__get()` returns that default, so the null tolerance in
    // the validator is load-bearing even though PHPStan reads the @property
    // docblock as non-nullable.
    $schema = new Schema(['type' => 'string']);

    expect($schema->allOf)->toBeNull()
        ->and(SchemaValidator::validate($schema, 'ok'))->toBe([]);
});

it('validates every branch of an anyOf composition', function (): void {
    $schema = new Schema([
        'anyOf' => [
            ['type' => 'string'],
            ['type' => 'integer'],
        ],
    ]);

    expect(SchemaValidator::validate($schema, 'text'))->toBe([])
        ->and(SchemaValidator::validate($schema, 7))->toBe([])
        ->and(SchemaValidator::validate($schema, true))->toBe([
            ['path' => '/', 'message' => 'value does not match any of the required schemas (anyOf)'],
        ]);
});

it('validates the constraints of an allOf composition', function (): void {
    $schema = new Schema([
        'allOf' => [
            ['type' => 'string', 'minLength' => 3],
        ],
    ]);

    expect(SchemaValidator::validate($schema, 'abc'))->toBe([])
        ->and(SchemaValidator::validate($schema, 'ab'))->toBe([
            ['path' => '/', 'message' => 'expected at least 3 characters, got 2'],
        ]);
});

it('validates a numerically named property as an object member', function (): void {
    // `{"1": "x"}` decodes to the int key 1, so a member map is array-keyed
    // rather than string-keyed; filtering it would skip real properties.
    $schema = new Schema([
        'type' => 'object',
        'properties' => ['1' => ['type' => 'integer']],
    ]);

    expect(SchemaValidator::validate($schema, [1 => 'x']))->toBe([
        ['path' => '/1', 'message' => "expected type 'integer', got string"],
    ]);
});

it('validates oneOf, Reference resolution, nullability, enum, and all JSON Schema types', function (): void {
    $oneOf = new Schema([
        'oneOf' => [
            ['type' => 'integer', 'multipleOf' => 2],
            ['type' => 'integer', 'multipleOf' => 3],
        ],
    ]);
    expect(SchemaValidator::validate($oneOf, 4))->toBe([])
        ->and(SchemaValidator::validate($oneOf, 6))->toHaveCount(1)
        ->and(SchemaValidator::validate($oneOf, 5))->toHaveCount(1);

    // Reference resolution via OpenApi document
    $doc = new OpenApi([
        'openapi' => '3.0.3',
        'info' => ['title' => 'T', 'version' => '1'],
        'components' => ['schemas' => ['Id' => ['type' => 'integer']]],
        'paths' => [],
    ]);
    $ref = new Reference(['$ref' => '#/components/schemas/Id']);
    $ref->setContext(new ReferenceContext($doc, '/'));
    $refSchema = new Schema(['properties' => ['id' => $ref]]);
    expect(SchemaValidator::validate($refSchema, ['id' => 1]))->toBe([])
        ->and(SchemaValidator::validate($refSchema, ['id' => 'bad']))->toHaveCount(1);

    // Nullability & untyped schemas
    expect(SchemaValidator::validate(new Schema(['type' => 'string', 'nullable' => true]), null))->toBe([])
        ->and(SchemaValidator::validate(new Schema([]), null))->toBe([])
        ->and(SchemaValidator::validate(new Schema(['type' => 'string']), null))->toBe([['path' => '/', 'message' => 'must not be null']]);

    // Enum & scalar/unknown types
    expect(SchemaValidator::validate(new Schema(['enum' => ['a', 'b']]), 'c'))->toHaveCount(1)
        ->and(SchemaValidator::validate(new Schema(['type' => 'boolean']), true))->toBe([])
        ->and(SchemaValidator::validate(new Schema(['type' => 'number']), 1.5))->toBe([])
        ->and(SchemaValidator::validate(new Schema(['type' => 'array']), [1, 2]))->toBe([])
        ->and(SchemaValidator::validate(new Schema(['type' => 'custom']), 'anything'))->toBe([]);
});

it('validates object and array bounds, required properties, uniqueItems, and item schemas', function (): void {
    $objSchema = new Schema([
        'type' => 'object',
        'minProperties' => 2,
        'maxProperties' => 2,
        'required' => ['a', 'b'],
        'properties' => [
            'a' => ['type' => 'string'],
            'b' => ['type' => 'integer'],
            'optional' => ['type' => 'boolean'],
        ],
    ]);

    expect(SchemaValidator::validate($objSchema, ['a' => 'ok']))->toHaveCount(2)
        ->and(SchemaValidator::validate($objSchema, ['a' => 'ok', 'b' => 1, 'c' => 2]))->toHaveCount(1);

    $arrSchema = new Schema([
        'type' => 'array',
        'minItems' => 2,
        'maxItems' => 2,
        'uniqueItems' => true,
        'items' => ['type' => 'integer'],
    ]);

    expect(SchemaValidator::validate($arrSchema, [1, 2]))->toBe([])
        ->and(SchemaValidator::validate($arrSchema, [1]))->toHaveCount(1)
        ->and(SchemaValidator::validate($arrSchema, [1, 1, 'bad']))->toHaveCount(3);
});

it('validates string lengths, patterns, formats, and numeric bounds/multipleOf', function (): void {
    $strSchema = new Schema([
        'type' => 'string',
        'minLength' => 3,
        'maxLength' => 5,
        'pattern' => '^[a-z]+$',
    ]);
    expect(SchemaValidator::validate($strSchema, 'abcd'))->toBe([])
        ->and(SchemaValidator::validate($strSchema, '123456'))->toHaveCount(2);

    // Every supported format + unknown annotation format
    expect(SchemaValidator::validate(new Schema(['type' => 'string', 'format' => 'date']), '2026-05-01'))->toBe([])
        ->and(SchemaValidator::validate(new Schema(['type' => 'string', 'format' => 'date']), 'not-a-date'))->toHaveCount(1)
        ->and(SchemaValidator::validate(new Schema(['type' => 'string', 'format' => 'date-time']), '2026-05-01T12:00:00+00:00'))->toBe([])
        ->and(SchemaValidator::validate(new Schema(['type' => 'string', 'format' => 'email']), 'user@example.com'))->toBe([])
        ->and(SchemaValidator::validate(new Schema(['type' => 'string', 'format' => 'uuid']), '550e8400-e29b-41d4-a716-446655440000'))->toBe([])
        ->and(SchemaValidator::validate(new Schema(['type' => 'string', 'format' => 'uri']), 'https://example.com'))->toBe([])
        ->and(SchemaValidator::validate(new Schema(['type' => 'string', 'format' => 'ipv4']), '192.168.0.1'))->toBe([])
        ->and(SchemaValidator::validate(new Schema(['type' => 'string', 'format' => 'ipv6']), '2001:db8::1'))->toBe([])
        ->and(SchemaValidator::validate(new Schema(['type' => 'string', 'format' => 'password']), 'secret'))->toBe([]);

    // Numeric bounds (inclusive & exclusive) and multipleOf
    $numSchema = new Schema([
        'type' => 'number',
        'minimum' => 10,
        'exclusiveMinimum' => true,
        'maximum' => 20,
        'exclusiveMaximum' => true,
        'multipleOf' => 2.5,
    ]);
    expect(SchemaValidator::validate($numSchema, 12.5))->toBe([])
        ->and(SchemaValidator::validate($numSchema, 10))->toHaveCount(1)
        ->and(SchemaValidator::validate($numSchema, 20))->toHaveCount(1)
        ->and(SchemaValidator::validate($numSchema, 13))->toHaveCount(1);
});
