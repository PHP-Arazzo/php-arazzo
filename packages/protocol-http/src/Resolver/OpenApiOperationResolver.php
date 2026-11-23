<?php

declare(strict_types=1);

namespace Alama\Arazzo\Protocol\Http\Resolver;

use Alama\Arazzo\Contracts\Interfaces\SourceResolverInterface;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\ResolvedOperation;
use Alama\Arazzo\Contracts\Spec\SourceDescription;
use Alama\Arazzo\Contracts\Spec\Step;
use Alama\Arazzo\Document\OperationResolverInterface;
use Alama\Arazzo\Sources\Normalizer\OpenApi30Normalizer;
use Alama\Arazzo\Sources\Normalizer\OpenApi31Normalizer;
use Alama\Arazzo\Sources\Normalizer\OpenApiVersionDetector;
use Alama\Arazzo\Sources\Normalizer\SourceReferenceParser;
use Alama\Arazzo\Sources\Resolver\Exceptions\UnsupportedSourceVersionException;
use Alama\Arazzo\Sources\Sources;
use cebe\openapi\spec\OpenApi;
use cebe\openapi\spec\Operation;
use RuntimeException;

/**
 * @internal stays out of the advertised contract; not part of the public API surface
 */
class OpenApiOperationResolver implements OperationResolverInterface
{
    private OpenApiVersionDetector $versionDetector;

    private OpenApi30Normalizer $normalizer30;

    private OpenApi31Normalizer $normalizer31;

    public function __construct(
        private OpenApiDocumentLoader $loader,
        ?OpenApiVersionDetector $versionDetector = null,
        ?OpenApi30Normalizer $normalizer30 = null,
        ?OpenApi31Normalizer $normalizer31 = null,
    ) {
        $this->versionDetector = $versionDetector ?? new OpenApiVersionDetector();
        $this->normalizer30 = $normalizer30 ?? new OpenApi30Normalizer();
        $this->normalizer31 = $normalizer31 ?? new OpenApi31Normalizer();
    }

    public static function create(?SourceResolverInterface $sourceResolver = null): self
    {
        $sources = $sourceResolver ?? Sources::create();

        return new self(new OpenApiDocumentLoader($sources));
    }

    /**
     * The detector this resolver was built with, so a caller wiring both
     * does not have to construct a second one.
     */
    public function versionDetector(): OpenApiVersionDetector
    {
        return $this->versionDetector;
    }

    public function supportsStep(Step $step, ArazzoDocument $document): bool
    {
        return SourceReferenceParser::isOpenApiStep($step, $document);
    }

    public function resolveModel(Step $step, ArazzoDocument $document): ResolvedOperation
    {
        return $this->resolve($step, $document);
    }

    public function resolve(Step $step, ArazzoDocument $document): OpenApiOperationHandle
    {
        $target = SourceReferenceParser::parseTarget($step, $document);
        $sourceName = $target['sourceName'];
        $operationReference = $target['operationReference'];

        $targetSource = $this->findSource($document, $sourceName, $step->stepId);
        $openApi = $this->loadOpenApi($targetSource, $sourceName);
        $rawDocument = $this->serializeOpenApi($openApi);
        $normalizer = $this->selectNormalizer($rawDocument, $sourceName);

        $located = $target['isPath']
            ? $this->findOperationByPath($openApi, $operationReference)
            : $this->findOperationById($openApi, $operationReference);

        if ($located === null) {
            throw new RuntimeException("Operation '{$operationReference}' not found in source '{$sourceName}'.");
        }

        [$foundPath, $foundMethod, $cebeOperation] = $located;
        $normalized = $normalizer->normalize($rawDocument, $foundPath, $foundMethod);

        return new OpenApiOperationHandle(
            new ResolvedOperation(
                $targetSource,
                $normalized,
                null,
                null,
                null,
                null,
                null,
            ),
            $openApi,
            $cebeOperation,
        );
    }

    private function findSource(ArazzoDocument $document, string $sourceName, string $stepId): SourceDescription
    {
        foreach ($document->sourceDescriptions as $source) {
            if ($source->name === $sourceName) {
                return $source;
            }
        }

        throw new RuntimeException("Source '{$sourceName}' not found in document for step '{$stepId}'.");
    }

    private function loadOpenApi(SourceDescription $source, string $sourceName): OpenApi
    {
        $openApi = $this->loader->load($source, getcwd() ?: '');
        if (!$openApi instanceof OpenApi) {
            throw new RuntimeException("Could not load or parse OpenAPI document for source '{$sourceName}'.");
        }

        return $openApi;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeOpenApi(OpenApi $openApi): array
    {
        $encoded = json_encode($openApi->getSerializableData(), JSON_THROW_ON_ERROR);
        $rawDocument = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);
        assert(is_array($rawDocument));

        /** @var array<string, mixed> $rawDocument */
        return $rawDocument;
    }

    /**
     * @param  array<string, mixed>  $rawDocument
     */
    private function selectNormalizer(array $rawDocument, string $sourceName): OpenApi30Normalizer|OpenApi31Normalizer
    {
        // Fail fast on unsupported source versions before any operation lookup.
        $detected = $this->versionDetector->detect($rawDocument);

        return match ($detected) {
            '3.0' => $this->normalizer30,
            '3.1' => $this->normalizer31,
            default => throw UnsupportedSourceVersionException::forVersion($detected, $sourceName),
        };
    }

    /**
     * @return array{0: string, 1: string, 2: Operation}|null
     */
    private function findOperationByPath(OpenApi $openApi, string $operationReference): ?array
    {
        // JSON Pointer segments are split BEFORE unescaping so multi-
        // segment paths like /pet/findByStatus (~1pet~1findByStatus)
        // stay a single PATH token.
        $refParts = explode('/', ltrim($operationReference, '/'));
        $unescaped = array_map(
            static fn (string $segment): string => str_replace(['~1', '~0'], ['/', '~'], $segment),
            $refParts,
        );

        if (count($unescaped) < 3 || $unescaped[0] !== 'paths') {
            throw new RuntimeException("Invalid operationPath reference '{$operationReference}'. Expected /paths/PATH/METHOD.");
        }

        $method = strtolower((string) array_pop($unescaped));
        array_shift($unescaped);
        $path = implode('/', $unescaped);

        $pathItem = $openApi->paths->getPath($path);
        $op = $pathItem !== null && isset($pathItem->{$method}) ? $pathItem->{$method} : null;

        return $op instanceof Operation ? [$path, $method, $op] : null;
    }

    /**
     * @return array{0: string, 1: string, 2: Operation}|null
     */
    private function findOperationById(OpenApi $openApi, string $operationReference): ?array
    {
        foreach ($openApi->paths as $path => $pathItem) {
            $match = $this->matchOperationInPathItem($pathItem, $operationReference);
            if ($match !== null) {
                $pathString = is_scalar($path) ? (string) $path : '';

                return [$pathString, $match[0], $match[1]];
            }
        }

        return null;
    }

    /**
     * @return array{0: string, 1: Operation}|null
     */
    private function matchOperationInPathItem(object $pathItem, string $operationReference): ?array
    {
        foreach (['get', 'put', 'post', 'delete', 'options', 'head', 'patch', 'trace'] as $method) {
            $op = isset($pathItem->{$method}) ? $pathItem->{$method} : null;
            if ($op instanceof Operation && $op->operationId === $operationReference) {
                return [$method, $op];
            }
        }

        return null;
    }
}
