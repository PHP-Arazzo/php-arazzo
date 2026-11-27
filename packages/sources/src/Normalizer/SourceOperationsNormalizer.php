<?php

declare(strict_types=1);

namespace Alama\Arazzo\Sources\Normalizer;

use Alama\Arazzo\Contracts\Data\ResolvedOperationCollection;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Enum\SourceType;
use Alama\Arazzo\Contracts\Spec\ResolvedOperation;
use Alama\Arazzo\Contracts\Spec\SourceDescription;
use Alama\Arazzo\Contracts\Spec\SourceDocument;
use Alama\Arazzo\Sources\Exceptions\SourceFetchException;
use Alama\Arazzo\Sources\Exceptions\SourceResolutionException;
use Alama\Arazzo\Sources\Policy\FetchPolicy;
use Alama\Arazzo\Sources\Resolver\UriReferenceResolver;
use Alama\Arazzo\Sources\Sources;
use Psr\Log\LoggerInterface;
use Throwable;

final readonly class SourceOperationsNormalizer
{
    public function __construct(
        private Sources $sources,
        private FetchPolicy $policy,
        private LoggerInterface $logger,
    ) {}

    public function normalizeSources(ArazzoDocument $document, string $basePath = ''): ResolvedOperationCollection
    {
        $effectiveBasePath = $basePath;
        if ($document->self !== null && trim($document->self) !== '') {
            $effectiveBasePath = UriReferenceResolver::resolve(trim($document->self), $basePath);
        }

        $operations = [];
        foreach ($document->sourceDescriptions as $source) {
            $operations += $this->normalizeSource($source, $document, $effectiveBasePath);
        }

        return ResolvedOperationCollection::fromArray($operations);
    }

    /**
     * @return array<string, ResolvedOperation>
     */
    private function normalizeSource(
        SourceDescription $source,
        ArazzoDocument $document,
        string $basePath,
    ): array {
        $resolved = null;
        $type = $source->type;

        if ($type === null) {
            [$resolved, $type] = $this->inferSourceType($source, $basePath);
        }

        if ($type === null) {
            return [];
        }

        $normalizer = $this->sources->findNormalizer($type);
        if ($normalizer === null) {
            return [];
        }

        $effectiveSource = $source->type === null
            ? new SourceDescription($source->name, $source->url, $type)
            : $source;

        try {
            $resolved ??= $this->sources->resolve($source, $basePath);
            $rawContent = json_encode($resolved->content, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            /** @var array<string, ResolvedOperation> $normalized */
            $normalized = $normalizer->normalize($effectiveSource, $rawContent, $document);

            return $normalized;
        } catch (Throwable $e) {
            $this->logger->warning('Skipping unresolvable source during normalization.', [
                'source' => $source->name,
                'url' => $source->url,
                'error' => $e->getMessage(),
            ]);

            if ($this->policy->strictReachability) {
                throw $e instanceof SourceResolutionException
                    ? $e
                    : SourceFetchException::resolutionFailed($source->name, $e);
            }

            return [];
        }
    }

    /**
     * @return array{0: ?SourceDocument, 1: ?SourceType}
     */
    private function inferSourceType(SourceDescription $source, string $basePath): array
    {
        try {
            $resolved = $this->sources->resolve($source, $basePath);
            $type = SourceDescription::inferFromContent($resolved->content);

            return [$resolved, $type];
        } catch (Throwable $e) {
            $this->logger->warning('Skipping unresolvable source during normalization.', [
                'source' => $source->name,
                'url' => $source->url,
                'error' => $e->getMessage(),
            ]);

            if ($this->policy->strictReachability) {
                throw $e instanceof SourceResolutionException
                    ? $e
                    : SourceFetchException::resolutionFailed($source->name, $e);
            }

            return [null, null];
        }
    }
}
