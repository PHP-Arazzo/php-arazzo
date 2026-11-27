<?php

declare(strict_types=1);

namespace Alama\Arazzo\Sources\Plugins\SourceNormalizer;

use Alama\Arazzo\Contracts\Attributes\AsSourceNormalizerPlugin;
use Alama\Arazzo\Contracts\Interfaces\SourceNormalizerInterface;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Enum\SourceType;
use Alama\Arazzo\Contracts\Spec\NormalizedOpenApiOperation;
use Alama\Arazzo\Contracts\Spec\ResolvedOperation;
use Alama\Arazzo\Contracts\Spec\SourceDescription;
use Alama\Arazzo\Sources\Exceptions\InvalidAsyncApiSourceException;
use Alama\Arazzo\Sources\Normalizer\AsyncApi20Normalizer;
use Alama\Arazzo\Sources\Normalizer\AsyncApi30Normalizer;
use Alama\Arazzo\Sources\Normalizer\AsyncApiVersionDetector;
use Symfony\Component\Yaml\Yaml;

/**
 * Port (D2): Turns a described AsyncAPI source + raw content into an operation/channel index.
 *
 * @internal stays out of the advertised contract; not part of the public API surface
 */
#[AsSourceNormalizerPlugin(SourceType::Asyncapi)]
final class AsyncApiSourceNormalizer implements SourceNormalizerInterface
{
    public function __construct(
        private readonly AsyncApiVersionDetector $versionDetector = new AsyncApiVersionDetector(),
        private readonly AsyncApi20Normalizer $normalizer20 = new AsyncApi20Normalizer(),
        private readonly AsyncApi30Normalizer $normalizer30 = new AsyncApi30Normalizer(),
    ) {}

    public function name(): string
    {
        return 'asyncapi';
    }

    public function priority(): int
    {
        return 0;
    }

    public function supports(SourceType $type): bool
    {
        return $type === SourceType::Asyncapi;
    }

    /**
     * @return array<string, ResolvedOperation>
     */
    public function normalize(SourceDescription $source, string $rawContent, ?ArazzoDocument $document = null): array
    {
        /** @var array<string, mixed>|null $rawDocument */
        $rawDocument = json_decode($rawContent, true);
        if ($rawDocument === null && class_exists(Yaml::class)) {
            try {
                $parsed = Yaml::parse($rawContent);
                if (is_array($parsed)) {
                    /** @var array<string, mixed> $rawDocument */
                    $rawDocument = $parsed;
                }
            } catch (\Throwable) {
                // Ignore and fall through to invalidJson exception
            }
        }
        if ($rawDocument === null) {
            throw InvalidAsyncApiSourceException::invalidJson();
        }

        if (!isset($rawDocument['channels']) || !is_array($rawDocument['channels']) || $rawDocument['channels'] === []) {
            throw InvalidAsyncApiSourceException::missingChannels();
        }

        $version = $this->versionDetector->detect($rawDocument);
        $channelNormalizer = $version === '2.0' ? $this->normalizer20 : $this->normalizer30;

        /** @var array<string, NormalizedOpenApiOperation> $normalizedChannels */
        $normalizedChannels = $channelNormalizer->normalizeChannels($rawDocument);

        return $this->buildIndex($rawDocument, $normalizedChannels, $source, $version);
    }

    /**
     * @param  array<string, mixed>  $rawDocument
     * @param  array<string, NormalizedOpenApiOperation>  $normalizedChannels
     * @return array<string, ResolvedOperation>
     */
    private function buildIndex(
        array $rawDocument,
        array $normalizedChannels,
        SourceDescription $source,
        string $version,
    ): array {
        $index = [];

        foreach ($normalizedChannels as $channelKey => $normalized) {
            $resolved = new ResolvedOperation(
                source: $source,
                normalized: $normalized,
                operationName: $channelKey,
            );

            $this->indexChannelKeys($index, $channelKey, $resolved, $source);

            if ($normalized->path !== $channelKey) {
                $this->indexChannelKeys($index, $normalized->path, $resolved, $source);
            }
        }

        if ($version === '3.0') {
            $this->indexAsyncApi3Operations($index, $rawDocument, $source);
        } elseif ($version === '2.0') {
            $this->indexAsyncApi2Operations($index, $rawDocument, $source);
        }

        return $index;
    }

    /**
     * @param  array<string, ResolvedOperation>  $index
     * @param  array<string, mixed>  $rawDocument
     */
    private function indexAsyncApi3Operations(array &$index, array $rawDocument, SourceDescription $source): void
    {
        if (!isset($rawDocument['operations']) || !is_array($rawDocument['operations'])) {
            return;
        }

        /** @var array<string, mixed> $operations */
        $operations = $rawDocument['operations'];
        foreach ($operations as $opKey => $opData) {
            if (!is_array($opData)) {
                continue;
            }

            $channelRef = $opData['channel']['$ref'] ?? null;
            if (is_string($channelRef)) {
                $refKey = basename($channelRef);
                $targetResolved = $index[$channelRef] ?? $index['#/channels/'.$refKey] ?? $index[$refKey] ?? null;
                if ($targetResolved !== null) {
                    $index[(string) $opKey] = $targetResolved;
                    $index['$sourceDescriptions.'.$source->name.'.'.$opKey] = $targetResolved;
                }
            }
        }
    }

    /**
     * @param  array<string, ResolvedOperation>  $index
     * @param  array<string, mixed>  $rawDocument
     */
    private function indexAsyncApi2Operations(array &$index, array $rawDocument, SourceDescription $source): void
    {
        if (!isset($rawDocument['channels']) || !is_array($rawDocument['channels'])) {
            return;
        }

        /** @var array<string, mixed> $rawChannels */
        $rawChannels = $rawDocument['channels'];
        foreach ($rawChannels as $channelKey => $channelData) {
            if (!is_array($channelData)) {
                continue;
            }

            $targetResolved = $index[(string) $channelKey];

            foreach (['publish', 'subscribe'] as $action) {
                $opId = $channelData[$action]['operationId'] ?? null;
                if (is_string($opId) && $opId !== '') {
                    $index[$opId] = $targetResolved;
                    $index['$sourceDescriptions.'.$source->name.'.'.$opId] = $targetResolved;
                }
            }
        }
    }

    /**
     * @param  array<string, ResolvedOperation>  $index
     */
    private function indexChannelKeys(array &$index, string $key, ResolvedOperation $resolved, SourceDescription $source): void
    {
        $escaped = str_replace(['~', '/'], ['~0', '~1'], $key);

        $index['#/channels/'.$escaped] = $resolved;
        $index['#/channels/'.$key] = $resolved;
        $index['channels/'.$escaped] = $resolved;
        $index['channels/'.$key] = $resolved;
        $index[$key] = $resolved;
        $index['$sourceDescriptions.'.$source->name.'.'.$key] = $resolved;
    }
}
