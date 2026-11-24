<?php

declare(strict_types=1);

namespace Alama\Arazzo\Sources\Plugins\SourceNormalizer;

use Alama\Arazzo\Contracts\Attributes\AsSourceNormalizerPlugin;
use Alama\Arazzo\Contracts\Interfaces\SourceNormalizerInterface;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Enum\SourceType;
use Alama\Arazzo\Contracts\Spec\ResolvedOperation;
use Alama\Arazzo\Contracts\Spec\SourceDescription;
use Alama\Arazzo\Sources\Exceptions\InvalidOpenApiSourceException;
use Alama\Arazzo\Sources\Normalizer\Interfaces\OpenApiNormalizerInterface;
use Alama\Arazzo\Sources\Normalizer\OpenApi30Normalizer;
use Alama\Arazzo\Sources\Normalizer\OpenApi31Normalizer;
use Alama\Arazzo\Sources\Normalizer\OpenApiVersionDetector;
use Alama\Arazzo\Sources\Resolver\Exceptions\UnsupportedSourceVersionException;

/**
 * Port (D2): turns a described source + raw content into an operation index.
 *
 * Pure array, zero vendor.
 *
 * @internal stays out of the advertised contract; not part of the public API surface
 */
#[AsSourceNormalizerPlugin(SourceType::Openapi)]
final class OpenApiSourceNormalizer implements SourceNormalizerInterface
{
    private const METHODS = ['get', 'put', 'post', 'delete', 'options', 'head', 'patch', 'trace'];

    public function __construct(
        private readonly OpenApiVersionDetector $versionDetector = new OpenApiVersionDetector(),
        private readonly OpenApi30Normalizer $normalizer30 = new OpenApi30Normalizer(),
        private readonly OpenApi31Normalizer $normalizer31 = new OpenApi31Normalizer(),
    ) {}

    public function name(): string
    {
        return 'openapi';
    }

    public function priority(): int
    {
        return 0;
    }

    public function supports(SourceType $type): bool
    {
        return $type === SourceType::Openapi;
    }

    /**
     * @return array<string, ResolvedOperation>
     */
    public function normalize(SourceDescription $source, string $rawContent, ?ArazzoDocument $document = null): array
    {
        /** @var array<string,mixed>|null $rawDocument */
        $rawDocument = json_decode($rawContent, true);
        if ($rawDocument === null) {
            throw InvalidOpenApiSourceException::invalidJson();
        }

        $version = $this->detectVersion($rawDocument, $source);
        $normalizer = $this->chooseNormalizer($version);

        return $this->buildIndex($rawDocument, $normalizer, $source);
    }

    /**
     * @param  array<string,mixed>  $rawDocument
     */
    private function detectVersion(array $rawDocument, SourceDescription $source): string
    {
        $version = $this->versionDetector->detect($rawDocument);
        if ($version === '2.0') {
            throw UnsupportedSourceVersionException::forVersion('2.0', (string) $source->name);
        }

        return $version;
    }

    private function chooseNormalizer(string $version): OpenApiNormalizerInterface
    {
        return $version === '3.0' ? $this->normalizer30 : $this->normalizer31;
    }

    /**
     * @param  array<string,mixed>  $rawDocument
     * @return array<string, ResolvedOperation>
     */
    private function buildIndex(array $rawDocument, OpenApiNormalizerInterface $normalizer, SourceDescription $source): array
    {
        $index = [];

        /** @var array<string, mixed> $paths */
        $paths = $rawDocument['paths'] ?? [];
        foreach ($paths as $path => $pathItem) {
            $path = (string) $path;
            if (!is_array($pathItem)) {
                continue;
            }
            foreach (self::METHODS as $method) {
                if (!isset($pathItem[$method]) || !is_array($pathItem[$method])) {
                    continue;
                }

                $operation = $pathItem[$method];
                $operationId = $operation['operationId'] ?? null;

                $normalized = $normalizer->normalize($rawDocument, $path, $method);

                $resolved = new ResolvedOperation(
                    source: $source,
                    normalized: $normalized,
                    rpcProtocol: null,
                    operationName: null,
                    rpcMethod: null,
                    graphqlOperation: null,
                    interaction: null,
                );

                if ($operationId !== null && is_string($operationId)) {
                    $index[$operationId] = $resolved;
                    $index['$sourceDescriptions.'.$source->name.'.'.$operationId] = $resolved;
                }

                $pointer = $this->jsonPointerForPath((string) $path, $method);
                $index[$pointer] = $resolved;
            }
        }

        return $index;
    }

    private function jsonPointerForPath(string $path, string $method): string
    {
        $segments = explode('/', ltrim($path, '/'));
        $escaped = array_map(
            static fn (string $seg): string => str_replace(['~', '/'], ['~0', '~1'], $seg),
            $segments,
        );

        return '#/paths/'.implode('/', $escaped).'/'.strtolower($method);
    }
}
