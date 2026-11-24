<?php

declare(strict_types=1);

use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Components;
use Alama\Arazzo\Contracts\Spec\Enum\SourceType;
use Alama\Arazzo\Contracts\Spec\Info;
use Alama\Arazzo\Contracts\Spec\SourceDescription;
use Alama\Arazzo\Contracts\Spec\Step;
use Alama\Arazzo\Contracts\Spec\StepFactory;
use Alama\Arazzo\Contracts\Spec\StepFlow;
use Alama\Arazzo\Contracts\Spec\StepIo;
use Alama\Arazzo\Contracts\Spec\StepTarget;
use Alama\Arazzo\Sources\Exceptions\InvalidOpenApiSourceException;
use Alama\Arazzo\Sources\Exceptions\InvalidSourceReferenceException;
use Alama\Arazzo\Sources\Normalizer\SourceReferenceParser;
use Alama\Arazzo\Sources\Plugins\SourceNormalizer\OpenApiSourceNormalizer;

function makeSourcesDoc(array $sources): ArazzoDocument
{
    return new ArazzoDocument(
        arazzo: '1.0.1',
        info: new Info('Test', null, null, '1.0.0'),
        sourceDescriptions: $sources,
        workflows: [],
        components: new Components([], [], [], []),
        specificationExtensions: [],
    );
}

test('SourceReferenceParser resolves and parses operationPath and operationId forms', function (): void {
    $openApi = new SourceDescription('petstore', 'https://example.test/openapi.json', SourceType::Openapi);
    $other = new SourceDescription('billing', 'https://example.test/billing.json', SourceType::Openapi);
    $arazzo = new SourceDescription('sub', 'https://example.test/sub.yaml', SourceType::Arazzo);

    $singleDoc = makeSourcesDoc([$openApi, $arazzo]);
    $multiDoc = makeSourcesDoc([$openApi, $other]);

    $opPathStep = StepFactory::http('s-path', null, new StepFlow(), new StepIo(), operationPath: '{$sourceDescriptions.petstore.url}#/paths/~1pets/get');
    $opIdUrlStep = StepFactory::http('s-id-url', null, new StepFlow(), new StepIo(), operationId: '{$sourceDescriptions.petstore.url}#listPets');
    $opIdDotStep = StepFactory::http('s-id-dot', null, new StepFlow(), new StepIo(), operationId: '$sourceDescriptions.petstore.listPets');
    $opIdPlainStep = StepFactory::http('s-id-plain', null, new StepFlow(), new StepIo(), operationId: 'listPets');
    $emptyStep = new Step('s-empty', null, StepTarget::workflow('other'), new StepFlow(), new StepIo());
    $badUrlPathStep = StepFactory::http('s-bad-path', null, new StepFlow(), new StepIo(), operationPath: 'not-a-valid-path');
    $badUrlIdStep = StepFactory::http('s-bad-id', null, new StepFlow(), new StepIo(), operationId: 'bad#ref');

    expect(SourceReferenceParser::isOpenApiStep($opPathStep, $singleDoc))->toBeTrue()
        ->and(SourceReferenceParser::isOpenApiStep($opIdUrlStep, $singleDoc))->toBeTrue()
        ->and(SourceReferenceParser::isOpenApiStep($opIdDotStep, $singleDoc))->toBeTrue()
        ->and(SourceReferenceParser::isOpenApiStep($opIdPlainStep, $singleDoc))->toBeTrue()
        ->and(SourceReferenceParser::isOpenApiStep($opIdPlainStep, $multiDoc))->toBeFalse()
        ->and(SourceReferenceParser::isOpenApiStep($emptyStep, $singleDoc))->toBeFalse()
        ->and(SourceReferenceParser::isOpenApiStep($badUrlPathStep, $singleDoc))->toBeFalse()
        ->and(SourceReferenceParser::isOpenApiStep($badUrlIdStep, $singleDoc))->toBeFalse();

    expect(SourceReferenceParser::parseTarget($opPathStep, $singleDoc))->toBe([
        'sourceName' => 'petstore',
        'operationReference' => '/paths/~1pets/get',
        'isPath' => true,
    ])->and(SourceReferenceParser::parseTarget($opIdUrlStep, $singleDoc))->toBe([
        'sourceName' => 'petstore',
        'operationReference' => 'listPets',
        'isPath' => false,
    ])->and(SourceReferenceParser::parseTarget($opIdDotStep, $singleDoc))->toBe([
        'sourceName' => 'petstore',
        'operationReference' => 'listPets',
        'isPath' => false,
    ])->and(SourceReferenceParser::parseTarget($opIdPlainStep, $singleDoc))->toBe([
        'sourceName' => 'petstore',
        'operationReference' => 'listPets',
        'isPath' => false,
    ]);

    expect(fn () => SourceReferenceParser::parseTarget($emptyStep, $singleDoc))
        ->toThrow(InvalidSourceReferenceException::class)
        ->and(fn () => SourceReferenceParser::parseTarget($badUrlPathStep, $singleDoc))
        ->toThrow(InvalidSourceReferenceException::class)
        ->and(fn () => SourceReferenceParser::parseTarget($badUrlIdStep, $singleDoc))
        ->toThrow(InvalidSourceReferenceException::class)
        ->and(fn () => SourceReferenceParser::parseTarget($opIdPlainStep, $multiDoc))
        ->toThrow(InvalidSourceReferenceException::class);
});

test('OpenApiSourceNormalizer rejects invalid JSON and skips non-array path items', function (): void {
    $source = new SourceDescription('api', 'https://example.test/openapi.json', SourceType::Openapi);
    $normalizer = new OpenApiSourceNormalizer();

    expect(fn () => $normalizer->normalize($source, '{not-json'))
        ->toThrow(InvalidOpenApiSourceException::class);

    $doc = json_encode([
        'openapi' => '3.0.3',
        'paths' => [
            '/ignored' => 'not-an-array',
            '/pets' => [
                'get' => ['operationId' => 'listPets', 'responses' => ['200' => ['description' => 'ok']]],
            ],
        ],
    ], JSON_THROW_ON_ERROR);

    expect($normalizer->normalize($source, $doc))->toHaveKey('listPets');
});
