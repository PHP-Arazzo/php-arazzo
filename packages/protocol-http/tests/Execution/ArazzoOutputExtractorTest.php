<?php

declare(strict_types=1);

namespace Alama\Arazzo\Tests\Protocol\Http\Execution;

use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Components;
use Alama\Arazzo\Contracts\Spec\Enum\ExpressionType;
use Alama\Arazzo\Contracts\Spec\Enum\SourceType;
use Alama\Arazzo\Contracts\Spec\Expression;
use Alama\Arazzo\Contracts\Spec\Info;
use Alama\Arazzo\Contracts\Spec\NormalizedOpenApiOperation;
use Alama\Arazzo\Contracts\Spec\ResolvedOperation;
use Alama\Arazzo\Contracts\Spec\Selector;
use Alama\Arazzo\Contracts\Spec\SourceDescription;
use Alama\Arazzo\Contracts\Spec\Step;
use Alama\Arazzo\Contracts\Spec\StepFactory;
use Alama\Arazzo\Contracts\Spec\StepFlow;
use Alama\Arazzo\Contracts\Spec\StepIo;
use Alama\Arazzo\Contracts\Spec\StepTarget;
use Alama\Arazzo\Contracts\State\WorkflowContext;
use Alama\Arazzo\Evaluation\EvaluationEngine;
use Alama\Arazzo\Expression\ExpressionEngine;
use Alama\Arazzo\Protocol\Http\Execution\StepOutputExtractor;
use Alama\Arazzo\Protocol\Http\Resolver\OpenApiDocumentLoader;
use Alama\Arazzo\Protocol\Http\Resolver\OpenApiOperationHandle;
use Alama\Arazzo\Protocol\Http\Resolver\OpenApiOperationResolver;
use Alama\Arazzo\Sources\Normalizer\OpenApi30Normalizer;
use Alama\Arazzo\Sources\Normalizer\OpenApi31Normalizer;
use Alama\Arazzo\Sources\Normalizer\OpenApiVersionDetector;
use Alama\Arazzo\Sources\Resolver\DefaultSourceResolver;
use Alama\Arazzo\Sources\Resolver\Fetchers\LocalFetcher;
use cebe\openapi\ReferenceContext;
use cebe\openapi\spec\OpenApi;
use cebe\openapi\spec\Operation;
use cebe\openapi\spec\Reference;
use cebe\openapi\spec\Response;
use cebe\openapi\spec\Responses;
use Mockery;
use Psr\Log\LoggerInterface;
use RuntimeException;

beforeEach(function () {
    $openApiJson = json_encode([
        'openapi' => '3.0.0',
        'info' => ['title' => 'Test', 'version' => '1.0'],
        'servers' => [['url' => 'https://api.test']],
        'paths' => [
            '/users' => [
                'post' => [
                    'operationId' => 'createUser',
                    'responses' => [
                        '201' => [
                            'description' => 'Created',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'id' => ['type' => 'integer'],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ]);

    $this->openApiFile = tempnam(sys_get_temp_dir(), 'openapi_').'.json';
    file_put_contents($this->openApiFile, $openApiJson);

    $this->makeExtractor = function (): StepOutputExtractor {
        $sourceResolver = new DefaultSourceResolver(fetchers: ['file' => new LocalFetcher()]);
        $resolver = new OpenApiOperationResolver(
            new OpenApiDocumentLoader($sourceResolver),
            new OpenApiVersionDetector(),
            new OpenApi30Normalizer(),
            new OpenApi31Normalizer(),
        );

        return new StepOutputExtractor($resolver, new EvaluationEngine(expression: new ExpressionEngine()), new ExpressionEngine());
    };

    $this->makeDocument = function (): ArazzoDocument {
        return new ArazzoDocument(
            arazzo: '1.0.1',
            info: new Info('Test', null, null, '1.0.0'),
            sourceDescriptions: [new SourceDescription('test-api', $this->openApiFile, SourceType::Openapi)],
            workflows: [],
            components: new Components([], [], [], []),
            specificationExtensions: [],
        );
    };
});

afterEach(function () {
    @unlink($this->openApiFile);
});

it('extracts output via runtime expression with schema cast', function () {
    $extractor = ($this->makeExtractor)();
    $document = ($this->makeDocument)();

    $step = StepFactory::http(
        stepId: 'create-user',
        description: null,
        flow: new StepFlow(),
        io: new StepIo(outputs: ['userId' => new Expression('{$steps.create-user.response.body#/id}')]),
        operationId: 'createUser',
    );

    $context = (new WorkflowContext('def_1'))->withStepResponse('create-user', [
        'statusCode' => 201,
        'headers' => [],
        'body' => ['id' => '123'],
    ]);

    $outputs = $extractor->extractOutputs($step, $context, $document);

    expect($outputs['userId'])->toBe(123);
});

it('extracts output via bare jsonpath', function () {
    $extractor = ($this->makeExtractor)();

    $step = new Step(
        stepId: 'step1',
        description: null,
        target: new StepTarget(),
        flow: new StepFlow(),
        io: new StepIo(outputs: ['firstId' => new Expression('$.users[0].id')]),
    );

    $context = (new WorkflowContext('def_1'))->withStepResponse('step1', [
        'statusCode' => 200,
        'headers' => [],
        'body' => ['users' => [['id' => 1], ['id' => 2]]],
    ]);

    $outputs = $extractor->extractOutputs($step, $context);

    expect($outputs['firstId'])->toBe(1);
});

it('extracts output via json pointer selector', function () {
    $extractor = ($this->makeExtractor)();

    $step = new Step(
        stepId: 'step1',
        description: null,
        target: new StepTarget(),
        flow: new StepFlow(),
        io: new StepIo(outputs: ['firstUser' => new Selector(null, '/users/0/id', ExpressionType::JsonPointer)]),
    );

    $context = (new WorkflowContext('def_1'))->withStepResponse('step1', [
        'statusCode' => 200,
        'headers' => [],
        'body' => ['users' => [['id' => 7], ['id' => 8]]],
    ]);

    $outputs = $extractor->extractOutputs($step, $context);

    expect($outputs['firstUser'])->toBe(7);
});

it('extracts output via selector with a context expression', function () {
    $extractor = ($this->makeExtractor)();

    $step = new Step(
        stepId: 's2',
        description: null,
        target: new StepTarget(),
        flow: new StepFlow(),
        io: new StepIo(outputs: ['token' => new Selector('{$steps.s1.outputs.auth}', '$.access.token', ExpressionType::JsonPath)]),
    );

    $context = (new WorkflowContext('def_1'))
        ->withStepResponse('s1', ['statusCode' => 200, 'headers' => [], 'body' => []])
        ->withStepOutput('s1', 'auth', ['access' => ['token' => 'jwt-9']]);

    $outputs = $extractor->extractOutputs($step, $context);

    expect($outputs['token'])->toBe('jwt-9');
});

it('handles literal outputs, missing documents, non-body expressions, schema traversal, casting types, Reference schemas, and cast failures', function () {
    $openApiJson = json_encode([
        'openapi' => '3.0.0',
        'info' => ['title' => 'Test', 'version' => '1.0'],
        'components' => [
            'schemas' => [
                'Body' => [
                    'type' => 'object',
                    'properties' => [
                        'price' => ['type' => 'number'],
                        'label' => ['type' => 'string'],
                        'active' => ['type' => 'boolean'],
                        'tags' => ['type' => 'array', 'items' => ['type' => 'integer']],
                        'meta' => ['type' => 'object'],
                        'untyped' => [],
                    ],
                ],
            ],
        ],
        'paths' => [
            '/items' => [
                'get' => [
                    'operationId' => 'getItem',
                    'responses' => [
                        'default' => [
                            'description' => 'Default',
                            'content' => [
                                'application/json' => [
                                    'schema' => ['$ref' => '#/components/schemas/Body'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ]);

    $file = tempnam(sys_get_temp_dir(), 'openapi_ext_').'.json';
    file_put_contents($file, $openApiJson);

    try {
        $warnings = [];
        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('warning')->andReturnUsing(function (string $msg) use (&$warnings): void {
            $warnings[] = $msg;
        });

        $sourceResolver = new DefaultSourceResolver(fetchers: ['file' => new LocalFetcher()]);
        $resolver = new OpenApiOperationResolver(new OpenApiDocumentLoader($sourceResolver));
        $extractor = new StepOutputExtractor($resolver, new EvaluationEngine(expression: new ExpressionEngine()), new ExpressionEngine(), $logger);

        $doc = new ArazzoDocument(
            arazzo: '1.0.1',
            info: new Info('Test', null, null, '1.0.0'),
            sourceDescriptions: [new SourceDescription('api', $file, SourceType::Openapi)],
            workflows: [],
            components: new Components([], [], [], []),
            specificationExtensions: [],
        );

        $step = StepFactory::http(
            stepId: 's1',
            description: null,
            flow: new StepFlow(),
            io: new StepIo(outputs: [
                'literal' => 'plain',
                'status' => new Expression('$statusCode'),
                'price' => new Expression('{$steps.s1.response.body#/price}'),
                'label' => new Expression('{$steps.s1.response.body#/label}'),
                'active' => new Expression('{$steps.s1.response.body#/active}'),
                'tags' => new Expression('{$steps.s1.response.body#/tags}'),
                'firstTag' => new Expression('{$steps.s1.response.body#/tags/0}'),
                'meta' => new Expression('{$steps.s1.response.body#/meta}'),
                'untyped' => new Expression('{$steps.s1.response.body#/untyped}'),
                'missing' => new Expression('{$steps.s1.response.body#/nope}'),
                'badInt' => new Expression('{$steps.s1.response.body#/tags/1}'),
            ]),
            operationId: 'getItem',
        );

        $context = (new WorkflowContext('def_1'))->withStepResponse('s1', [
            'statusCode' => 200,
            'headers' => [],
            'body' => [
                'price' => '12.5',
                'label' => 99,
                'active' => 'true',
                'tags' => ['7', 'not-an-int'],
                'meta' => ['k' => 'v'],
                'untyped' => 'raw',
                'nope' => 'val',
            ],
        ]);

        // Without document -> skips schema cast
        $withoutDoc = $extractor->extractOutputs($step, $context, null);
        expect($withoutDoc['literal'])->toBe('plain')
            ->and($withoutDoc['price'])->toBe('12.5');

        // With document -> casts against Reference schema and logs warning on invalid cast
        $withDoc = $extractor->extractOutputs($step, $context, $doc);
        expect($withDoc['price'])->toBe(12.5)
            ->and($withDoc['label'])->toBe('99')
            ->and($withDoc['active'])->toBeTrue()
            ->and($withDoc['tags'])->toBe(['7', 'not-an-int'])
            ->and($withDoc['firstTag'])->toBe(7)
            ->and($withDoc['meta'])->toBe(['k' => 'v'])
            ->and($withDoc['untyped'])->toBe('raw')
            ->and($withDoc['missing'])->toBe('val')
            ->and($withDoc['badInt'])->toBe('not-an-int')
            ->and($warnings)->not->toBeEmpty();

        // Missing operation (RuntimeException caught)
        $unknownOpStep = StepFactory::http(
            stepId: 's1',
            description: null,
            flow: new StepFlow(),
            io: new StepIo(outputs: ['price' => new Expression('{$steps.s1.response.body#/price}')]),
            operationId: 'missingOp',
        );
        expect($extractor->extractOutputs($unknownOpStep, $context, $doc)['price'])->toBe('12.5');

        // Operation with null responses and operation with unresolved status response + Reference schema
        $mockResolver = Mockery::mock(OpenApiOperationResolver::class);
        $source = new SourceDescription('api', $file, SourceType::Openapi);
        $norm = new NormalizedOpenApiOperation(path: '/x', method: 'get');

        $noRespOp = new Operation([]);
        $noRespOp->responses = null;
        $emptyRespOp = new Operation(['responses' => []]);

        $refDoc = new OpenApi([
            'openapi' => '3.0.3',
            'info' => ['title' => 'T', 'version' => '1'],
            'components' => ['schemas' => ['Num' => ['type' => 'object', 'properties' => ['price' => ['type' => 'integer']]]]],
            'paths' => [],
        ]);
        $ref = new Reference(['$ref' => '#/components/schemas/Num']);
        $ref->setContext(new ReferenceContext($refDoc, '/'));
        $refOp = new Operation([
            'responses' => [
                '200' => new Response([
                    'description' => 'ok',
                    'content' => ['application/json' => ['schema' => $ref]],
                ]),
            ],
        ]);

        $mockResolver->shouldReceive('resolve')->andReturn(
            new OpenApiOperationHandle(new ResolvedOperation($source, $norm), new OpenApi([]), $noRespOp),
            new OpenApiOperationHandle(new ResolvedOperation($source, $norm), new OpenApi([]), $emptyRespOp),
            new OpenApiOperationHandle(new ResolvedOperation($source, $norm), $refDoc, $refOp),
        );
        $mockExtractor = new StepOutputExtractor($mockResolver, new EvaluationEngine(expression: new ExpressionEngine()), new ExpressionEngine());
        $castStep = StepFactory::http('s1', null, new StepFlow(), new StepIo(outputs: ['price' => new Expression('{$steps.s1.response.body#/price}')]), operationId: 'op');

        expect($mockExtractor->extractOutputs($castStep, $context, $doc)['price'])->toBe('12.5')
            ->and($mockExtractor->extractOutputs($castStep, $context, $doc)['price'])->toBe('12.5')
            ->and($mockExtractor->extractOutputs($castStep, $context, $doc)['price'])->toBe(12);

        // Non-scalar statusCode exercises stringify default branch and non-array step record exercises stepRecordValue
        $weirdContext = (new WorkflowContext('def_1'))->withStepResponse('s1', [
            'statusCode' => ['not-scalar'],
            'headers' => [],
            'body' => ['price' => '12'],
        ]);
        $refResolver = Mockery::mock(OpenApiOperationResolver::class);
        $refResolver->shouldReceive('resolve')->andReturn(
            new OpenApiOperationHandle(new ResolvedOperation($source, $norm), $refDoc, new Operation(['responses' => ['default' => new Response(['description' => 'ok', 'content' => ['application/json' => ['schema' => $ref]]])]])),
        );
        $refExtractor = new StepOutputExtractor($refResolver, new EvaluationEngine(expression: new ExpressionEngine()), new ExpressionEngine());
        expect($refExtractor->extractOutputs($castStep, $weirdContext, $doc)['price'])->toBe(12);
    } finally {
        @unlink($file);
    }
});
