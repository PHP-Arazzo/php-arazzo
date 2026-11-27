<?php

declare(strict_types=1);

namespace Alama\Arazzo\Sources\Inspection;

use Alama\Arazzo\Contracts\Data\ResolvedOperationCollection;
use Alama\Arazzo\Contracts\Data\SourceInspectionResult;
use Alama\Arazzo\Contracts\Data\SourceResolution;
use Alama\Arazzo\Contracts\Data\SourceResolutionCollection;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\ResolvedOperation;
use Alama\Arazzo\Contracts\Spec\SourceDescription;
use Alama\Arazzo\Sources\Exceptions\SourceParseException;
use Alama\Arazzo\Sources\Resolver\UriReferenceResolver;
use Alama\Arazzo\Sources\Sources;
use Psr\Log\LoggerInterface;
use Throwable;

final readonly class SourceInspector
{
    public function __construct(
        private Sources $sources,
        private LoggerInterface $logger,
    ) {}

    public function inspect(ArazzoDocument $document, string $basePath = ''): SourceInspectionResult
    {
        $effectiveBasePath = $basePath;
        if ($document->self !== null && trim($document->self) !== '') {
            $effectiveBasePath = UriReferenceResolver::resolve(trim($document->self), $basePath);
        }

        $resolutions = [];
        /** @var array<string, ResolvedOperation> $operations */
        $operations = [];

        foreach ($document->sourceDescriptions as $source) {
            $resolution = $this->inspectSingleSource($source, $effectiveBasePath);
            $resolutions[$source->name] = $resolution;

            if ($resolution->isOk() && $resolution->document !== null) {
                $type = $resolution->detectedType ?? $source->type;
                if ($type !== null) {
                    $normalizer = $this->sources->findNormalizer($type);
                    if ($normalizer !== null) {
                        try {
                            $rawContent = json_encode($resolution->document->content, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
                            $effectiveSource = $source->type === null
                                ? new SourceDescription($source->name, $source->url, $type)
                                : $source;
                            /** @var array<string, ResolvedOperation> $normalized */
                            $normalized = $normalizer->normalize($effectiveSource, $rawContent, $document);
                            $operations += $normalized;
                        } catch (Throwable $e) {
                            $this->logger->warning('Failed to normalize source operations during inspection.', [
                                'source' => $source->name,
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }
                }
            }
        }

        return new SourceInspectionResult(
            SourceResolutionCollection::fromArray($resolutions, isResolved: true),
            ResolvedOperationCollection::fromArray($operations)->toKnownOperations(),
        );
    }

    private function inspectSingleSource(SourceDescription $source, string $basePath): SourceResolution
    {
        try {
            $resolvedDoc = $this->sources->resolve($source, $basePath);
        } catch (SourceParseException $e) {
            return SourceResolution::unparseable($source->name, $source->url, $e->getMessage());
        } catch (Throwable $e) {
            return SourceResolution::unreadable($source->name, $source->url, $e->getMessage());
        }

        $detectedType = SourceDescription::inferFromContent($resolvedDoc->content);

        if ($source->type !== null) {
            if ($detectedType === null || $detectedType !== $source->type) {
                return SourceResolution::typeMismatch($source->name, $source->url, $source->type, $detectedType);
            }

            return SourceResolution::valid($source->name, $source->url, $detectedType, $resolvedDoc);
        }

        if ($detectedType === null) {
            return SourceResolution::typeInferenceFailed(
                $source->name,
                $source->url,
                "Failed to infer source type for '{$source->name}': unrecognized document format",
            );
        }

        return SourceResolution::valid($source->name, $source->url, $detectedType, $resolvedDoc);
    }
}
