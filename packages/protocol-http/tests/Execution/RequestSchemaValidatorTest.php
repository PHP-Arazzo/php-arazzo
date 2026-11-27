<?php

declare(strict_types=1);

namespace Alama\Arazzo\Tests\Protocol\Http\Execution;

use Alama\Arazzo\Contracts\Exceptions\SchemaValidationException;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Components;
use Alama\Arazzo\Contracts\Spec\Enum\SourceType;
use Alama\Arazzo\Contracts\Spec\Info;
use Alama\Arazzo\Contracts\Spec\NormalizedOpenApiOperation;
use Alama\Arazzo\Contracts\Spec\OpenApiPayload;
use Alama\Arazzo\Contracts\Spec\ResolvedOperation;
use Alama\Arazzo\Contracts\Spec\SourceDescription;
use Alama\Arazzo\Contracts\Spec\StepFactory;
use Alama\Arazzo\Contracts\Spec\StepFlow;
use Alama\Arazzo\Contracts\Spec\StepIo;
use Alama\Arazzo\Protocol\Http\Execution\RequestSchemaValidator;
use Alama\Arazzo\Protocol\Http\Resolver\OpenApiOperationHandle;
use cebe\openapi\ReferenceContext;
use cebe\openapi\spec\OpenApi;
use cebe\openapi\spec\Operation;
use cebe\openapi\spec\Parameter;
use cebe\openapi\spec\Reference;
use cebe\openapi\spec\RequestBody;
use cebe\openapi\spec\Schema;

function requestValidatorFixture(): array
{
    $source = new SourceDescription('api', 'file:///api.json', SourceType::Openapi);
    $doc = new ArazzoDocument('1.0.1', new Info('T', null, null, '1'), [$source], [], new Components([], [], [], []), []);
    $step = StepFactory::http('createThing', null, new StepFlow(), new StepIo(), operationId: 'op');
    $norm = new NormalizedOpenApiOperation(path: '/things', method: 'post');

    return [$source, $doc, $step, $norm];
}

it('passes a conforming request body (S1)', function (): void {
    [$source, $doc, $step, $norm] = requestValidatorFixture();

    $op = new Operation([
        'requestBody' => new RequestBody([
            'required' => true,
            'content' => [
                'application/json' => [
                    'schema' => new Schema([
                        'type' => 'object',
                        'required' => ['name'],
                        'properties' => [
                            'name' => ['type' => 'string'],
                        ],
                    ]),
                ],
            ],
        ]),
    ]);

    $openApi = new OpenApi(['paths' => []]);
    $handle = new OpenApiOperationHandle(new ResolvedOperation($source, $norm), $openApi, $op);
    $validator = new RequestSchemaValidator();

    $payload = new OpenApiPayload(body: ['name' => 'test'], bodyMediaType: 'application/json');

    $validator->validateRequest($step, $handle, $payload, $doc);
    expect(true)->toBeTrue();
});

it('rejects a type-violating body and throws SchemaValidationException naming step, part and pointer (S2)', function (): void {
    [$source, $doc, $step, $norm] = requestValidatorFixture();

    $op = new Operation([
        'requestBody' => new RequestBody([
            'required' => true,
            'content' => [
                'application/json' => [
                    'schema' => new Schema([
                        'type' => 'object',
                        'required' => ['name'],
                        'properties' => [
                            'name' => ['type' => 'string'],
                        ],
                    ]),
                ],
            ],
        ]),
    ]);

    $openApi = new OpenApi(['paths' => []]);
    $handle = new OpenApiOperationHandle(new ResolvedOperation($source, $norm), $openApi, $op);
    $validator = new RequestSchemaValidator();

    $payload = new OpenApiPayload(body: ['name' => 123], bodyMediaType: 'application/json');

    try {
        $validator->validateRequest($step, $handle, $payload, $doc);
        $this->fail('Expected SchemaValidationException');
    } catch (SchemaValidationException $e) {
        expect($e->stepId)->toBe('createThing')
            ->and($e->getMessage())->toContain("Request schema validation failed for step 'createThing'")
            ->and($e->violations)->toHaveCount(1)
            ->and($e->violations[0]['path'])->toBe('/body/name')
            ->and($e->violations[0]['message'])->toContain("expected type 'string', got int");
    }
});

it('rejects a parameter that violates its declared schema naming /parameters/query/id (S3)', function (): void {
    [$source, $doc, $step, $norm] = requestValidatorFixture();

    $op = new Operation([
        'parameters' => [
            new Parameter([
                'name' => 'id',
                'in' => 'query',
                'required' => false,
                'schema' => new Schema(['type' => 'integer']),
            ]),
        ],
    ]);

    $openApi = new OpenApi(['paths' => []]);
    $handle = new OpenApiOperationHandle(new ResolvedOperation($source, $norm), $openApi, $op);
    $validator = new RequestSchemaValidator();

    $payload = new OpenApiPayload(query: ['id' => 'abc']);

    try {
        $validator->validateRequest($step, $handle, $payload, $doc);
        $this->fail('Expected SchemaValidationException');
    } catch (SchemaValidationException $e) {
        expect($e->stepId)->toBe('createThing')
            ->and($e->violations)->toHaveCount(1)
            ->and($e->violations[0]['path'])->toBe('/parameters/query/id')
            ->and($e->violations[0]['message'])->toContain("expected type 'integer', got string");
    }
});

it('skips operations without schemas and passes vacuously (S4)', function (): void {
    [$source, $doc, $step, $norm] = requestValidatorFixture();

    $op = new Operation([]);
    $openApi = new OpenApi(['paths' => []]);
    $handle = new OpenApiOperationHandle(new ResolvedOperation($source, $norm), $openApi, $op);
    $validator = new RequestSchemaValidator();

    $payload = new OpenApiPayload(path: ['p' => 1], query: ['q' => 'x'], body: ['any' => 'thing']);

    $validator->validateRequest($step, $handle, $payload, $doc);
    expect(true)->toBeTrue();
});

it('validates path, header and cookie parameters per location (S5)', function (): void {
    [$source, $doc, $step, $norm] = requestValidatorFixture();

    $op = new Operation([
        'parameters' => [
            new Parameter([
                'name' => 'itemId',
                'in' => 'path',
                'required' => true,
                'schema' => new Schema(['type' => 'integer']),
            ]),
            new Parameter([
                'name' => 'X-Trace',
                'in' => 'header',
                'required' => false,
                'schema' => new Schema(['type' => 'string']),
            ]),
            new Parameter([
                'name' => 'session',
                'in' => 'cookie',
                'required' => false,
                'schema' => new Schema(['type' => 'string']),
            ]),
        ],
    ]);

    $openApi = new OpenApi(['paths' => []]);
    $handle = new OpenApiOperationHandle(new ResolvedOperation($source, $norm), $openApi, $op);
    $validator = new RequestSchemaValidator();

    $payload = new OpenApiPayload(
        path: ['itemId' => 'bad-item'],
        header: ['X-Trace' => 12345],
        cookie: ['session' => ['invalid' => 'cookie']],
    );

    try {
        $validator->validateRequest($step, $handle, $payload, $doc);
        $this->fail('Expected SchemaValidationException');
    } catch (SchemaValidationException $e) {
        $paths = array_column($e->violations, 'path');
        expect($paths)->toContain('/parameters/path/itemId')
            ->and($paths)->toContain('/parameters/header/X-Trace')
            ->and($paths)->toContain('/parameters/cookie/session');
    }
});

it('fails when a required parameter is missing', function (): void {
    [$source, $doc, $step, $norm] = requestValidatorFixture();

    $op = new Operation([
        'parameters' => [
            new Parameter([
                'name' => 'apiKey',
                'in' => 'query',
                'required' => true,
                'schema' => new Schema(['type' => 'string']),
            ]),
        ],
    ]);

    $openApi = new OpenApi(['paths' => []]);
    $handle = new OpenApiOperationHandle(new ResolvedOperation($source, $norm), $openApi, $op);
    $validator = new RequestSchemaValidator();

    $payload = new OpenApiPayload(query: []);

    try {
        $validator->validateRequest($step, $handle, $payload, $doc);
        $this->fail('Expected SchemaValidationException');
    } catch (SchemaValidationException $e) {
        expect($e->violations)->toHaveCount(1)
            ->and($e->violations[0]['path'])->toBe('/parameters/query/apiKey')
            ->and($e->violations[0]['message'])->toContain("missing required query parameter 'apiKey'");
    }
});

it('fails when a required request body is omitted', function (): void {
    [$source, $doc, $step, $norm] = requestValidatorFixture();

    $op = new Operation([
        'requestBody' => new RequestBody([
            'required' => true,
            'content' => [
                'application/json' => [
                    'schema' => new Schema(['type' => 'object']),
                ],
            ],
        ]),
    ]);

    $openApi = new OpenApi(['paths' => []]);
    $handle = new OpenApiOperationHandle(new ResolvedOperation($source, $norm), $openApi, $op);
    $validator = new RequestSchemaValidator();

    $payload = new OpenApiPayload(body: null);

    try {
        $validator->validateRequest($step, $handle, $payload, $doc);
        $this->fail('Expected SchemaValidationException');
    } catch (SchemaValidationException $e) {
        expect($e->violations)->toHaveCount(1)
            ->and($e->violations[0]['path'])->toBe('/body')
            ->and($e->violations[0]['message'])->toBe('request body is required');
    }
});

it('fails when request body content-type is unsupported by the operation', function (): void {
    [$source, $doc, $step, $norm] = requestValidatorFixture();

    $op = new Operation([
        'requestBody' => new RequestBody([
            'required' => true,
            'content' => [
                'application/json' => [
                    'schema' => new Schema(['type' => 'object']),
                ],
            ],
        ]),
    ]);

    $openApi = new OpenApi(['paths' => []]);
    $handle = new OpenApiOperationHandle(new ResolvedOperation($source, $norm), $openApi, $op);
    $validator = new RequestSchemaValidator();

    $payload = new OpenApiPayload(body: ['name' => 'test'], bodyMediaType: 'application/xml');

    try {
        $validator->validateRequest($step, $handle, $payload, $doc);
        $this->fail('Expected SchemaValidationException');
    } catch (SchemaValidationException $e) {
        expect($e->violations)->toHaveCount(1)
            ->and($e->violations[0]['path'])->toBe('/body')
            ->and($e->violations[0]['message'])->toContain("content type 'application/xml' is not supported");
    }
});

it('resolves $ref schemas in parameters and requestBody', function (): void {
    [$source, $doc, $step, $norm] = requestValidatorFixture();

    $openApi = new OpenApi([
        'openapi' => '3.0.3',
        'info' => ['title' => 'T', 'version' => '1'],
        'components' => [
            'schemas' => [
                'ThingInput' => [
                    'type' => 'object',
                    'required' => ['name'],
                    'properties' => [
                        'name' => ['type' => 'string'],
                    ],
                ],
            ],
        ],
        'paths' => [],
    ]);

    $schemaRef = new Reference(['$ref' => '#/components/schemas/ThingInput']);
    $schemaRef->setContext(new ReferenceContext($openApi, '/'));

    $op = new Operation([
        'requestBody' => new RequestBody([
            'required' => true,
            'content' => [
                'application/json' => ['schema' => $schemaRef],
            ],
        ]),
    ]);

    $handle = new OpenApiOperationHandle(new ResolvedOperation($source, $norm), $openApi, $op);
    $validator = new RequestSchemaValidator();

    // Valid
    $validator->validateRequest($step, $handle, new OpenApiPayload(body: ['name' => 'valid'], bodyMediaType: 'application/json'), $doc);

    // Invalid
    expect(fn () => $validator->validateRequest($step, $handle, new OpenApiPayload(body: ['name' => 999], bodyMediaType: 'application/json'), $doc))
        ->toThrow(SchemaValidationException::class);
});
