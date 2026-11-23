<?php

declare(strict_types=1);

namespace Alama\Arazzo\Tests\Protocol\Http\Resolver;

use Alama\Arazzo\Contracts\Interfaces\SourceResolverInterface;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Components;
use Alama\Arazzo\Contracts\Spec\Enum\SourceType;
use Alama\Arazzo\Contracts\Spec\Info;
use Alama\Arazzo\Contracts\Spec\SourceDescription;
use Alama\Arazzo\Contracts\Spec\SourceDocument;
use Alama\Arazzo\Contracts\Spec\StepFactory;
use Alama\Arazzo\Contracts\Spec\StepFlow;
use Alama\Arazzo\Contracts\Spec\StepIo;
use Alama\Arazzo\Protocol\Http\Resolver\OpenApiDocumentLoader;
use Alama\Arazzo\Protocol\Http\Resolver\OpenApiOperationResolver;
use Alama\Arazzo\Sources\Exceptions\UnsupportedSourceVersionException;
use Alama\Arazzo\Sources\Normalizer\OpenApi30Normalizer;
use Alama\Arazzo\Sources\Normalizer\OpenApi31Normalizer;
use Alama\Arazzo\Sources\Normalizer\OpenApiVersionDetector;
use Alama\Arazzo\Sources\Resolver\DefaultSourceResolver;
use Alama\Arazzo\Sources\Resolver\Fetchers\LocalFetcher;
use Mockery;
use RuntimeException;

it('rejects Swagger 2.0 sources with a typed error instead of mis-routing them to the 3.0 normalizer', function () {
    $swaggerJson = json_encode([
        'swagger' => '2.0',
        'info' => ['title' => 'Legacy', 'version' => '1.0'],
        'basePath' => '/v2',
        'paths' => [
            '/pets' => [
                'get' => [
                    'operationId' => 'listPets',
                    'responses' => ['200' => ['description' => 'OK']],
                ],
            ],
        ],
    ]);

    $swaggerFile = tempnam(sys_get_temp_dir(), 'swagger_').'.json';
    file_put_contents($swaggerFile, $swaggerJson);

    try {
        $sourceResolver = new DefaultSourceResolver(fetchers: ['file' => new LocalFetcher()]);
        $resolver = new OpenApiOperationResolver(
            new OpenApiDocumentLoader($sourceResolver),
            new OpenApiVersionDetector(),
            new OpenApi30Normalizer(),
            new OpenApi31Normalizer(),
        );

        $document = new ArazzoDocument(
            arazzo: '1.0.1',
            info: new Info('Test', null, null, '1.0.0'),
            sourceDescriptions: [new SourceDescription('legacy-api', $swaggerFile, SourceType::Openapi)],
            workflows: [],
            components: new Components([], [], [], []),
            specificationExtensions: [],
        );

        $step = StepFactory::http(
            stepId: 'step1',
            description: null,
            flow: new StepFlow(),
            io: new StepIo(),
            operationId: '$sourceDescriptions.legacy-api.listPets',
        );

        $resolver->resolve($step, $document);
    } finally {
        @unlink($swaggerFile);
    }
})->throws(UnsupportedSourceVersionException::class, 'declares version \'2.0\', which is not supported');

it('resolves a supported operation into both the cebe handle and the vendor-free model', function (): void {
    $openApiJson = json_encode([
        'openapi' => '3.0.3',
        'info' => ['title' => 'Pets', 'version' => '1.0'],
        'paths' => [
            '/pets' => [
                'get' => [
                    'operationId' => 'listPets',
                    'responses' => ['200' => ['description' => 'OK']],
                ],
            ],
        ],
    ]);

    $openApiFile = tempnam(sys_get_temp_dir(), 'openapi_').'.json';
    file_put_contents($openApiFile, $openApiJson);

    try {
        $sourceResolver = new DefaultSourceResolver(fetchers: ['file' => new LocalFetcher()]);
        $resolver = new OpenApiOperationResolver(
            new OpenApiDocumentLoader($sourceResolver),
            new OpenApiVersionDetector(),
            new OpenApi30Normalizer(),
            new OpenApi31Normalizer(),
        );

        $document = new ArazzoDocument(
            arazzo: '1.0.1',
            info: new Info('Test', null, null, '1.0.0'),
            sourceDescriptions: [new SourceDescription('pets-api', $openApiFile, SourceType::Openapi)],
            workflows: [],
            components: new Components([], [], [], []),
            specificationExtensions: [],
        );

        $step = StepFactory::http(
            stepId: 'step1',
            description: null,
            flow: new StepFlow(),
            io: new StepIo(),
            operationId: '$sourceDescriptions.pets-api.listPets',
        );

        $handle = $resolver->resolve($step, $document);
        $model = $resolver->resolveModel($step, $document);

        expect($handle->cebeOperation->operationId)->toBe('listPets')
            ->and($handle->openApi->openapi)->toBe('3.0.3')
            ->and($handle->operation->normalized->path)->toBe('/pets')
            ->and($handle->operation->normalized->method)->toBe('get')
            ->and($model->normalized->path)->toBe('/pets')
            ->and($model->normalized->method)->toBe('get');
    } finally {
        @unlink($openApiFile);
    }
});

it('resolves by operationPath, supports OpenAPI 3.1, and exposes versionDetector and supportsStep', function (): void {
    $openApiJson = json_encode([
        'openapi' => '3.1.0',
        'info' => ['title' => 'Pets', 'version' => '1.0'],
        'paths' => [
            '/pet/findByStatus' => [
                'get' => [
                    'operationId' => 'findPets',
                    'responses' => ['200' => ['description' => 'OK']],
                ],
            ],
        ],
    ]);

    $openApiFile = tempnam(sys_get_temp_dir(), 'openapi31_').'.json';
    file_put_contents($openApiFile, $openApiJson);

    try {
        $sourceResolver = new DefaultSourceResolver(fetchers: ['file' => new LocalFetcher()]);
        $resolver = OpenApiOperationResolver::create($sourceResolver);

        $document = new ArazzoDocument(
            arazzo: '1.0.1',
            info: new Info('Test', null, null, '1.0.0'),
            sourceDescriptions: [new SourceDescription('pets', $openApiFile, SourceType::Openapi)],
            workflows: [],
            components: new Components([], [], [], []),
            specificationExtensions: [],
        );

        $pathStep = StepFactory::http(
            stepId: 's1',
            description: null,
            flow: new StepFlow(),
            io: new StepIo(),
            operationPath: '{$sourceDescriptions.pets.url}#/paths/~1pet~1findByStatus/GET',
        );

        expect($resolver->versionDetector())->toBeInstanceOf(OpenApiVersionDetector::class)
            ->and($resolver->supportsStep($pathStep, $document))->toBeTrue();

        $handle = $resolver->resolve($pathStep, $document);
        expect($handle->operation->normalized->path)->toBe('/pet/findByStatus')
            ->and($handle->operation->normalized->method)->toBe('get');

        // Unknown operationId throws RuntimeException
        $missingIdStep = StepFactory::http('s2', null, new StepFlow(), new StepIo(), operationId: '$sourceDescriptions.pets.nope');
        expect(fn () => $resolver->resolve($missingIdStep, $document))->toThrow(RuntimeException::class, 'not found');

        // Invalid operationPath throws RuntimeException
        $badPathStep = StepFactory::http('s3', null, new StepFlow(), new StepIo(), operationPath: '{$sourceDescriptions.pets.url}#/invalid');
        expect(fn () => $resolver->resolve($badPathStep, $document))->toThrow(RuntimeException::class, 'Invalid operationPath');

        // Missing source throws RuntimeException
        $missingSourceStep = StepFactory::http('s4', null, new StepFlow(), new StepIo(), operationId: '$sourceDescriptions.unknown.findPets');
        expect(fn () => $resolver->resolve($missingSourceStep, $document))->toThrow(RuntimeException::class, "Source 'unknown' not found");
    } finally {
        @unlink($openApiFile);
    }
});

it('throws when loader returns null for an unparseable OpenAPI document', function (): void {
    $document = new ArazzoDocument(
        arazzo: '1.0.1',
        info: new Info('Test', null, null, '1.0.0'),
        sourceDescriptions: [new SourceDescription('pets', 'file:///missing.json', SourceType::Openapi)],
        workflows: [],
        components: new Components([], [], [], []),
        specificationExtensions: [],
    );
    $step = StepFactory::http('s1', null, new StepFlow(), new StepIo(), operationId: '$sourceDescriptions.pets.list');

    $sourceResolver = Mockery::mock(SourceResolverInterface::class);
    $sourceResolver->shouldReceive('resolve')->once()->andReturn(
        new SourceDocument('pets', SourceType::Openapi, 'file:///missing.json', ['invalid' => "\xB1\x31"]),
    );
    $resolver = new OpenApiOperationResolver(new OpenApiDocumentLoader($sourceResolver));
    expect(fn () => $resolver->resolve($step, $document))->toThrow(RuntimeException::class, 'Could not load');
});
