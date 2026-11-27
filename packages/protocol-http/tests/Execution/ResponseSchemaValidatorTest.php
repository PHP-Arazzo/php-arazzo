<?php

declare(strict_types=1);

namespace Alama\Arazzo\Tests\Protocol\Http\Execution;

use Alama\Arazzo\Contracts\Exceptions\SchemaValidationException;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Components;
use Alama\Arazzo\Contracts\Spec\Enum\SourceType;
use Alama\Arazzo\Contracts\Spec\Info;
use Alama\Arazzo\Contracts\Spec\NormalizedOpenApiOperation;
use Alama\Arazzo\Contracts\Spec\ResolvedOperation;
use Alama\Arazzo\Contracts\Spec\SourceDescription;
use Alama\Arazzo\Contracts\Spec\StepFactory;
use Alama\Arazzo\Contracts\Spec\StepFlow;
use Alama\Arazzo\Contracts\Spec\StepIo;
use Alama\Arazzo\Protocol\Http\Execution\ResponseSchemaValidator;
use Alama\Arazzo\Protocol\Http\Resolver\OpenApiOperationHandle;
use Alama\Arazzo\Protocol\Http\Resolver\OpenApiOperationResolver;
use cebe\openapi\ReferenceContext;
use cebe\openapi\spec\OpenApi;
use cebe\openapi\spec\Operation;
use cebe\openapi\spec\Reference;
use cebe\openapi\spec\Response;
use Mockery;
use RuntimeException;

function responseValidatorFixture(): array
{
    $source = new SourceDescription('api', 'file:///api.json', SourceType::Openapi);
    $doc = new ArazzoDocument('1.0.1', new Info('T', null, null, '1'), [$source], [], new Components([], [], [], []), []);
    $step = StepFactory::http('s1', null, new StepFlow(), new StepIo(), operationId: 'op');
    $norm = new NormalizedOpenApiOperation(path: '/items', method: 'get');

    return [$source, $doc, $step, $norm];
}

it('validates response bodies against exact status, default fallback, and $ref responses/schemas', function (): void {
    [$source, $doc, $step, $norm] = responseValidatorFixture();

    $openApi = new OpenApi([
        'openapi' => '3.0.3',
        'info' => ['title' => 'T', 'version' => '1'],
        'components' => [
            'responses' => [
                'OkRef' => [
                    'description' => 'OK',
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => '#/components/schemas/Item'],
                        ],
                    ],
                ],
            ],
            'schemas' => [
                'Item' => [
                    'type' => 'object',
                    'required' => ['id'],
                    'properties' => ['id' => ['type' => 'integer']],
                ],
            ],
        ],
        'paths' => [],
    ]);

    $responseRef = new Reference(['$ref' => '#/components/responses/OkRef']);
    $responseRef->setContext(new ReferenceContext($openApi, '/'));
    $schemaRef = new Reference(['$ref' => '#/components/schemas/Item']);
    $schemaRef->setContext(new ReferenceContext($openApi, '/'));

    // Attach context to nested schema ref inside components.responses.OkRef
    $openApi->components->responses['OkRef']->content['application/json']->schema->setContext(new ReferenceContext($openApi, '/'));

    $opWithRef = new Operation([
        'responses' => [
            '200' => $responseRef,
            'default' => new Response([
                'description' => 'Default',
                'content' => ['application/json' => ['schema' => $schemaRef]],
            ]),
        ],
    ]);

    $resolver = Mockery::mock(OpenApiOperationResolver::class);
    $resolver->shouldReceive('resolve')->andReturn(
        new OpenApiOperationHandle(new ResolvedOperation($source, $norm), $openApi, $opWithRef),
    );

    $validator = new ResponseSchemaValidator($resolver);

    // Valid body with charset suffix on content type
    $validator->validateResponseSchema($step, 200, 'application/json; charset=utf-8', ['id' => 1], $doc);

    // Fallback to default response + SchemaValidationException on mismatch
    expect(fn () => $validator->validateResponseSchema($step, 418, 'application/json', ['id' => 'bad'], $doc))
        ->toThrow(SchemaValidationException::class);

    // Unmatched media type skips validation cleanly
    $validator->validateResponseSchema($step, 200, 'text/plain', 'raw', $doc);
});

it('treats missing document, resolver failure, and missing responses as valid by default', function (): void {
    [$source, $doc, $step, $norm] = responseValidatorFixture();

    $noRespOp = new Operation([]);
    $noRespOp->responses = null;

    $resolver = Mockery::mock(OpenApiOperationResolver::class);
    $resolver->shouldReceive('resolve')->once()->andThrow(new RuntimeException('missing'));
    $resolver->shouldReceive('resolve')->once()->andReturn(
        new OpenApiOperationHandle(new ResolvedOperation($source, $norm), new OpenApi([]), $noRespOp),
    );
    $resolver->shouldReceive('resolve')->once()->andReturn(
        new OpenApiOperationHandle(new ResolvedOperation($source, $norm), new OpenApi([]), new Operation(['responses' => []])),
    );

    $validator = new ResponseSchemaValidator($resolver);

    // Null document
    $validator->validateResponseSchema($step, 200, 'application/json', ['any' => true], null);
    // Resolver throws RuntimeException
    $validator->validateResponseSchema($step, 200, 'application/json', ['any' => true], $doc);
    // Operation has null responses
    $validator->validateResponseSchema($step, 200, 'application/json', ['any' => true], $doc);
    // Operation has empty responses map
    $validator->validateResponseSchema($step, 200, 'application/json', ['any' => true], $doc);

    expect(true)->toBeTrue();
});
