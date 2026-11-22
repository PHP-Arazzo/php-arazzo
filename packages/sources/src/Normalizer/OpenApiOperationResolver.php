<?php

declare(strict_types=1);

namespace Alama\Arazzo\Sources\Normalizer;

use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Step;
use Alama\Arazzo\Document\OperationResolverInterface;
use Alama\Arazzo\Document\ResolvedOperation;
use Alama\Arazzo\Sources\Resolver\Exceptions\UnsupportedSourceVersionException;
use cebe\openapi\spec\Operation;
use RuntimeException;

/**
 * @internal stays out of the advertised contract; not part of the public API surface
 */
class OpenApiOperationResolver implements OperationResolverInterface
{
    public function __construct(
        private OpenApiDocumentLoader $loader,
        private OpenApiVersionDetector $versionDetector,
        private OpenApi30Normalizer $normalizer30,
        private OpenApi31Normalizer $normalizer31,
    ) {}

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
        $isPath = $target['isPath'];

        $targetSource = null;
        foreach ($document->sourceDescriptions as $source) {
            if ($source->name === $sourceName) {
                $targetSource = $source;
                break;
            }
        }

        if (!$targetSource) {
            throw new RuntimeException("Source '{$sourceName}' not found in document for step '{$step->stepId}'.");
        }

        $openApi = $this->loader->load($targetSource, getcwd() ?: '');
        if (!$openApi) {
            throw new RuntimeException("Could not load or parse OpenAPI document for source '{$sourceName}'.");
        }

        $encoded = json_encode($openApi->getSerializableData());
        if ($encoded === false) {
            throw new RuntimeException('Failed to encode OpenAPI document');
        }
        $rawDocument = json_decode($encoded, true);
        if (!is_array($rawDocument)) {
            throw new RuntimeException('Failed to decode OpenAPI document into an array');
        }
        /** @var array<string, mixed> $rawDocument */

        // Fail fast on unsupported source versions before any operation lookup.
        $detected = $this->versionDetector->detect($rawDocument);
        if (!in_array($detected, ['3.0', '3.1'], true)) {
            throw UnsupportedSourceVersionException::forVersion($detected, (string) $sourceName);
        }

        $normalizer = $detected === '3.0' ? $this->normalizer30 : $this->normalizer31;

        /** @var string|null $foundPath */
        $foundPath = null;
        /** @var string|null $foundMethod */
        $foundMethod = null;
        /** @var Operation|null $cebeOperation */
        $cebeOperation = null;

        if ($isPath) {
            // JSON Pointer segments are split BEFORE unescaping so multi-
            // segment paths like /pet/findByStatus (~1pet~1findByStatus)
            // stay a single PATH token.
            $refParts = explode('/', ltrim($operationReference, '/'));
            $unescaped = array_map(
                fn (string $segment): string => str_replace(['~1', '~0'], ['/', '~'], $segment),
                $refParts,
            );

            if (count($unescaped) < 3 || $unescaped[0] !== 'paths') {
                throw new RuntimeException("Invalid operationPath reference '{$operationReference}'. Expected /paths/PATH/METHOD.");
            }

            $foundMethod = strtolower((string) array_pop($unescaped));
            array_shift($unescaped);
            $foundPath = implode('/', $unescaped);

            $pathItem = $openApi->paths->getPath($foundPath);
            if ($pathItem && isset($pathItem->{$foundMethod})) {
                $op = $pathItem->{$foundMethod};
                if ($op instanceof Operation) {
                    $cebeOperation = $op;
                }
            }
        } else {
            foreach ($openApi->paths as $path => $pathItem) {
                foreach (['get', 'put', 'post', 'delete', 'options', 'head', 'patch', 'trace'] as $method) {
                    if (isset($pathItem->{$method})) {
                        $op = $pathItem->{$method};
                        /** @phpstan-ignore-next-line */
                        if ($op instanceof Operation && $op->operationId === $operationReference) {
                            if (is_string($path)) {
                                $foundPath = $path;
                            } elseif (is_scalar($path)) {
                                $foundPath = (string) $path;
                            }
                            $foundMethod = $method;
                            $cebeOperation = $op;
                            break 2;
                        }
                    }
                }
            }
        }

        if (!$foundPath || !$foundMethod || !$cebeOperation) {
            throw new RuntimeException("Operation '{$operationReference}' not found in source '{$sourceName}'.");
        }

        $normalized = $normalizer->normalize($rawDocument, (string) $foundPath, (string) $foundMethod);

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
}
