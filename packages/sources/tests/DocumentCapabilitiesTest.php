<?php

declare(strict_types=1);

use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Enum\SourceType;
use Alama\Arazzo\Contracts\Spec\SourceDescription;
use Alama\Arazzo\Contracts\Spec\SourceDocument;
use Alama\Arazzo\Document\ResolvedOperation;
use Alama\Arazzo\Sources\SourceGraph;

function capabilityDocument(array $workflows, array $sourceDescriptions = []): ArazzoDocument
{
    $document = SourceGraph::document();

    return $document->loadFromJson(json_encode([
        'arazzo' => '1.0.0',
        'info' => ['title' => 'Capability', 'version' => '1.0'],
        'sourceDescriptions' => $sourceDescriptions,
        'workflows' => $workflows,
    ]));
}

it('resolves a source description to a source document through the face', function (): void {
    $source = new SourceDescription('pets', __DIR__.'/fixtures/document/openapi30.yaml', SourceType::Openapi);

    $resolved = SourceGraph::loader()->resolveSource($source, __DIR__.'/fixtures/document');

    expect($resolved)->toBeInstanceOf(SourceDocument::class)
        ->and($resolved->name)->toBe('pets')
        ->and($resolved->type)->toBe(SourceType::Openapi)
        ->and($resolved->content)->toHaveKey('openapi');
});

it('detects OpenAPI versions through the loader face', function (): void {
    $loader = SourceGraph::loader();

    expect($loader->detectOpenApiVersion(['openapi' => '3.0.0']))->toBe('3.0')
        ->and($loader->detectOpenApiVersion(['openapi' => '3.1.0']))->toBe('3.1')
        ->and($loader->detectOpenApiVersion(['swagger' => '2.0']))->toBe('2.0');
});

it('throws on unsupported OpenAPI version during detectOpenApiVersion', function (): void {
    SourceGraph::loader()->detectOpenApiVersion(['openapi' => '4.0.0']);
})->throws(InvalidArgumentException::class, 'Unsupported or missing OpenAPI/Swagger version');

it('resolves an operation target through the operations face', function (): void {
    $operations = SourceGraph::operations();
    $arazzo = capabilityDocument(
        sourceDescriptions: [
            ['name' => 'pets', 'url' => __DIR__.'/fixtures/document/openapi30.yaml', 'type' => 'openapi'],
        ],
        workflows: [
            ['workflowId' => 'w', 'steps' => [
                ['stepId' => 'list', 'operationId' => 'listPets'],
            ]],
        ],
    );

    $resolved = $operations->resolve($arazzo->workflows[0]->steps[0], $arazzo);

    expect($resolved)->toBeInstanceOf(ResolvedOperation::class)
        ->and($resolved->source->name)->toBe('pets')
        ->and($resolved->normalized->path)->toBe('/pets')
        ->and($resolved->normalized->method)->toBe('get');
});

it('rejects an operation resolution when step has no operationId and no operationPath', function (): void {
    $operations = SourceGraph::operations();
    $arazzo = capabilityDocument(workflows: [
        ['workflowId' => 'w', 'steps' => [
            ['stepId' => 'orphan', 'requestBody' => ['payload' => ['foo' => 'bar']]],
        ]],
    ]);

    $operations->resolve($arazzo->workflows[0]->steps[0], $arazzo);
})->throws(RuntimeException::class, 'must have either operationId or operationPath');

it('reports unknown workflows during input validation', function (): void {
    $document = SourceGraph::document();
    $arazzo = capabilityDocument(workflows: [
        ['workflowId' => 'w', 'steps' => []],
    ]);

    $result = $document->validateInputs($arazzo, 'ghost', []);

    expect($result->isValid())->toBeFalse()
        ->and($result->errors[0]->code)->toBe('preflight.unknown_workflow');
});

it('accepts inputs matching the declared workflow schema', function (): void {
    $document = SourceGraph::document();
    $arazzo = capabilityDocument(workflows: [
        ['workflowId' => 'w', 'inputs' => [
            'type' => 'object',
            'properties' => ['rideId' => ['type' => 'integer']],
            'required' => ['rideId'],
        ], 'steps' => []],
    ]);

    $result = $document->validateInputs($arazzo, 'w', ['rideId' => 42]);

    expect($result->isValid())->toBeTrue(json_encode($result->errors));
});

it('rejects inputs violating the declared workflow schema', function (): void {
    $document = SourceGraph::document();
    $arazzo = capabilityDocument(workflows: [
        ['workflowId' => 'w', 'inputs' => [
            'type' => 'object',
            'properties' => ['rideId' => ['type' => 'integer']],
            'required' => ['rideId'],
        ], 'steps' => []],
    ]);

    $result = $document->validateInputs($arazzo, 'w', ['rideId' => 'not-an-int']);

    expect($result->isValid())->toBeFalse()
        ->and($result->errors[0]->code)->toBe('preflight.inputs_schema');
});

it('treats documents without an inputs schema as unconstrained', function (): void {
    $document = SourceGraph::document();
    $arazzo = capabilityDocument(workflows: [
        ['workflowId' => 'w', 'steps' => []],
    ]);

    $result = $document->validateInputs($arazzo, 'w', ['anything' => ['goes' => true]]);

    expect($result->isValid())->toBeTrue();
});
