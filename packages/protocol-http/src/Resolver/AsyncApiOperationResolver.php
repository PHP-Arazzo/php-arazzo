<?php

declare(strict_types=1);

namespace Alama\Arazzo\Protocol\Http\Resolver;

use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\ResolvedOperation;
use Alama\Arazzo\Contracts\Spec\SourceDescription;
use Alama\Arazzo\Contracts\Spec\Step;
use Alama\Arazzo\Contracts\State\WorkflowContext;
use Alama\Arazzo\Document\OperationResolverInterface;
use Alama\Arazzo\Protocol\Http\Exceptions\AsyncApiResolutionException;
use Alama\Arazzo\Protocol\Http\Exceptions\AsyncApiStepException;
use Alama\Arazzo\Sources\Plugins\SourceNormalizer\AsyncApiSourceNormalizer;
use Alama\Arazzo\Sources\Sources;
use Alama\Arazzo\Sources\SourcesInterface;
use Throwable;

/**
 * Resolves AsyncAPI step channel targets against described AsyncAPI sources.
 *
 * @internal stays out of the advertised contract; not part of the public API surface
 */
class AsyncApiOperationResolver implements OperationResolverInterface
{
    private readonly AsyncApiPathInterpolator $interpolator;

    public function __construct(
        private readonly SourcesInterface $sources,
        private readonly AsyncApiSourceNormalizer $normalizer = new AsyncApiSourceNormalizer(),
        ?AsyncApiPathInterpolator $interpolator = null,
        private readonly string $basePath = '',
    ) {
        $this->interpolator = $interpolator ?? new AsyncApiPathInterpolator();
    }

    public static function create(?SourcesInterface $sources = null, string $basePath = ''): self
    {
        return new self($sources ?? Sources::create(), basePath: $basePath);
    }

    public function supportsStep(Step $step, ArazzoDocument $document): bool
    {
        if ($step->isAsyncAction() || ($step->target->channelPath !== null && $step->target->channelPath !== '')) {
            return true;
        }

        return $document->findSource($step->target->channelPath ?? '') !== null;
    }

    public function resolve(Step $step, ArazzoDocument $document): ResolvedOperation
    {
        $channelPath = $step->target->channelPath;
        if ($channelPath === null || $channelPath === '') {
            throw $step->isReceiveAction()
                ? AsyncApiStepException::missingReceiveChannelPath($step->stepId)
                : AsyncApiStepException::missingSendChannelPath($step->stepId);
        }

        $direct = AsyncApiFallbackResolver::tryDirectHttp($channelPath);
        if ($direct !== null) {
            return $direct;
        }

        $inline = AsyncApiFallbackResolver::tryInlineMock($channelPath, $document);
        if ($inline !== null) {
            return $inline;
        }

        return $this->resolveFromSource($channelPath, $step->stepId, $document);
    }

    private function resolveFromSource(string $channelPath, string $stepId, ArazzoDocument $document): ResolvedOperation
    {
        [$sourceName, $channelReference] = $this->parseChannelReference($channelPath, $stepId, $document);

        $targetSource = $document->findSource($sourceName)
            ?? throw AsyncApiResolutionException::sourceNotFound($sourceName, $stepId);

        try {
            $effectiveBasePath = $this->basePath !== ''
                ? $this->basePath
                : ($document->self !== null && trim($document->self) !== '' ? trim($document->self) : (getcwd() ?: ''));
            $sourceDoc = $this->sources->resolve($targetSource, $effectiveBasePath);
            $rawContent = json_encode($sourceDoc->content, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $index = $this->normalizer->normalize($targetSource, $rawContent, $document);
        } catch (Throwable $e) {
            throw AsyncApiResolutionException::documentLoadFailed($sourceName, $e);
        }

        return $this->lookupChannel($index, $channelReference, $sourceName);
    }

    /**
     * @param  array<string, ResolvedOperation>  $index
     */
    private function lookupChannel(array $index, string $reference, string $sourceName): ResolvedOperation
    {
        $resolved = $index[$reference]
            ?? $index['#'.$reference]
            ?? $index['#/channels/'.ltrim($reference, '/')]
            ?? $index[ltrim($reference, '#')]
            ?? $index[basename($reference)]
            ?? null;

        if ($resolved === null) {
            throw AsyncApiResolutionException::channelNotFound($reference, $sourceName);
        }

        return $resolved;
    }

    public function resolveTargetUrl(Step $step, ?WorkflowContext $context, ArazzoDocument $document): string
    {
        $channelPath = $step->target->channelPath ?? '';
        if (preg_match('#^https?://#i', $channelPath) === 1) {
            return $this->interpolator->interpolate($channelPath, $step, $context, $document);
        }

        $resolved = $this->resolve($step, $document);
        $serverUrl = $resolved->normalized->resolvedServerUrl;
        $path = $this->interpolator->interpolate($resolved->normalized->path, $step, $context, $document);

        if ($serverUrl === null || $serverUrl === '') {
            return $path;
        }

        return rtrim($serverUrl, '/').'/'.ltrim($path, '/');
    }

    /**
     * @return array{0: string, 1: string} [sourceName, channelReference]
     */
    private function parseChannelReference(string $channelPath, string $stepId, ArazzoDocument $document): array
    {
        if (str_contains($channelPath, '#')) {
            $parts = explode('#', $channelPath, 2);
            $left = $parts[0];
            $right = $parts[1] ?? '';

            if (str_starts_with($left, '{$sourceDescriptions.') && str_ends_with($left, '.url}')) {
                return [substr($left, 21, -5), $right];
            }

            if ($left === '') {
                $singleSource = $this->findSingleAsyncApiSource($document);
                if ($singleSource !== null) {
                    return [$singleSource->name, '#'.$right];
                }
            }
        }

        if (preg_match('/^\$sourceDescriptions\.([^.]+)\.(.+)$/', $channelPath, $matches) === 1) {
            return [$matches[1], $matches[2]];
        }

        $singleSource = $this->findSingleAsyncApiSource($document);
        if ($singleSource !== null) {
            return [$singleSource->name, $channelPath];
        }

        throw AsyncApiResolutionException::unresolvableChannelTarget($stepId, $channelPath);
    }

    private function findSingleAsyncApiSource(ArazzoDocument $document): ?SourceDescription
    {
        $asyncSources = array_filter(
            $document->sourceDescriptions,
            static fn (SourceDescription $s): bool => $s->type !== null && $s->type->value === 'asyncapi',
        );

        return count($asyncSources) === 1 ? reset($asyncSources) : null;
    }
}
