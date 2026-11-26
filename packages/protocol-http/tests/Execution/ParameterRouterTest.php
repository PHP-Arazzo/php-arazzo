<?php

declare(strict_types=1);

namespace Alama\Arazzo\Tests\Protocol\Http\Execution;

use Alama\Arazzo\Contracts\Spec\NormalizedOpenApiOperation;
use Alama\Arazzo\Contracts\Spec\OpenApiPayload;
use Alama\Arazzo\Protocol\Http\Execution\ParameterRouter;

it('routes auto parameters into correct buckets based on OpenAPI operation declarations', function (): void {
    $normalized = new NormalizedOpenApiOperation(
        path: '/items/{id}',
        method: 'get',
        resolvedServerUrl: 'https://api.example.com',
        pathParameters: ['id' => ['name' => 'id', 'in' => 'path', 'schema' => ['type' => 'integer']]],
        queryParameters: ['filter' => ['name' => 'filter', 'in' => 'query', 'schema' => ['type' => 'string']]],
        headerParameters: ['X-Custom' => ['name' => 'X-Custom', 'in' => 'header', 'schema' => ['type' => 'string']]],
        cookieParameters: ['sess' => ['name' => 'sess', 'in' => 'cookie', 'schema' => ['type' => 'string']]],
        requestBodies: [],
        responses: [],
    );

    $payload = new OpenApiPayload(
        auto: [
            'id' => '42',
            'filter' => 'active',
            'X-Custom' => 'trace-1',
            'sess' => 'abc',
            'unknownParam' => 'default-query',
        ],
    );

    $router = new ParameterRouter();
    $routed = $router->routeAndCast($normalized, $payload);

    expect($routed->auto)->toBe([])
        ->and($routed->path)->toBe(['id' => 42]) // Coerced from string '42' to int 42
        ->and($routed->query)->toEqual(['filter' => 'active', 'unknownParam' => 'default-query'])
        ->and($routed->header)->toBe(['X-Custom' => 'trace-1'])
        ->and($routed->cookie)->toBe(['sess' => 'abc']);
});

it('coerces numeric and boolean strings to declared schema scalar types', function (): void {
    $normalized = new NormalizedOpenApiOperation(
        path: '/test',
        method: 'get',
        resolvedServerUrl: null,
        pathParameters: [],
        queryParameters: [
            'count' => ['name' => 'count', 'in' => 'query', 'schema' => ['type' => 'integer']],
            'rate' => ['name' => 'rate', 'in' => 'query', 'schema' => ['type' => 'number']],
            'active' => ['name' => 'active', 'in' => 'query', 'schema' => ['type' => 'boolean']],
            'tags' => ['name' => 'tags', 'in' => 'query', 'schema' => ['type' => 'array']],
        ],
        headerParameters: [],
        cookieParameters: [],
        requestBodies: [],
        responses: [],
    );

    $payload = new OpenApiPayload(
        query: [
            'count' => '100',
            'rate' => '12.5',
            'active' => 'true',
            'tags' => ['a', 'b'],
        ],
    );

    $router = new ParameterRouter();
    $routed = $router->routeAndCast($normalized, $payload);

    expect($routed->query['count'])->toBe(100)
        ->and($routed->query['rate'])->toBe(12.5)
        ->and($routed->query['active'])->toBeTrue()
        ->and($routed->query['tags'])->toBe(['a', 'b']);
});

it('preserves raw value when type casting fails', function (): void {
    $normalized = new NormalizedOpenApiOperation(
        path: '/test',
        method: 'get',
        resolvedServerUrl: null,
        pathParameters: [],
        queryParameters: [
            'count' => ['name' => 'count', 'in' => 'query', 'schema' => ['type' => 'integer']],
        ],
        headerParameters: [],
        cookieParameters: [],
        requestBodies: [],
        responses: [],
    );

    $payload = new OpenApiPayload(
        query: [
            'count' => 'not-a-number',
        ],
    );

    $router = new ParameterRouter();
    $routed = $router->routeAndCast($normalized, $payload);

    // Stays 'not-a-number' so that SchemaValidator can detect the violation
    expect($routed->query['count'])->toBe('not-a-number');
});
