<?php

declare(strict_types=1);

namespace Alama\Arazzo\Protocol\Http\Resolver;

use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Enum\SourceType;
use Alama\Arazzo\Contracts\Spec\Expression;
use Alama\Arazzo\Contracts\Spec\NormalizedOpenApiOperation;
use Alama\Arazzo\Contracts\Spec\ResolvedOperation;
use Alama\Arazzo\Contracts\Spec\SourceDescription;
use Alama\Arazzo\Contracts\Spec\Step;
use Alama\Arazzo\Contracts\State\WorkflowContext;
use Alama\Arazzo\Document\OperationResolverInterface;
use Alama\Arazzo\Engine\Execution\ReusableParameterResolver;
use Alama\Arazzo\Evaluation\EvaluationEngine;
use Alama\Arazzo\Evaluation\EvaluationEngineInterface;
use Alama\Arazzo\Expression\Data\EvaluationContext;
use Alama\Arazzo\Expression\ExpressionEngine;
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
final class AsyncApiOperationResolver implements OperationResolverInterface
{
    public function __construct(
        private readonly SourcesInterface $sources,
        private readonly AsyncApiSourceNormalizer $normalizer = new AsyncApiSourceNormalizer(),
        private readonly ?EvaluationEngineInterface $evaluationEngine = null,
        private readonly string $basePath = '',
    ) {}

    public static function create(?SourcesInterface $sources = null, string $basePath = ''): self
    {
        return new self($sources ?? Sources::create(), basePath: $basePath);
    }

    public function supportsStep(Step $step, ArazzoDocument $document): bool
    {
        return $step->isAsyncAction() || ($step->target->channelPath !== null && $step->target->channelPath !== '');
    }

    public function resolve(Step $step, ArazzoDocument $document): ResolvedOperation
    {
        $channelPath = $step->target->channelPath;
        if ($channelPath === null || $channelPath === '') {
            if ($step->isReceiveAction()) {
                throw AsyncApiStepException::missingReceiveChannelPath($step->stepId);
            }
            throw AsyncApiStepException::missingSendChannelPath($step->stepId);
        }

        // Direct HTTP URL fallback
        if (preg_match('#^https?://#i', $channelPath) === 1) {
            $syntheticSource = new SourceDescription('direct', $channelPath, SourceType::Asyncapi);
            $normalized = new NormalizedOpenApiOperation(
                path: $channelPath,
                method: 'POST',
                resolvedServerUrl: $channelPath,
                pathParameters: [],
                queryParameters: [],
                headerParameters: [],
                cookieParameters: [],
                requestBodies: [],
                responses: [],
            );

            return new ResolvedOperation($syntheticSource, $normalized, operationName: $channelPath);
        }

        // Bare channel path without source descriptions (e.g. standalone/mock workflows)
        if (!str_contains($channelPath, '#') && !str_starts_with($channelPath, '$sourceDescriptions.')) {
            $asyncSources = array_filter(
                $document->sourceDescriptions,
                static fn (SourceDescription $s): bool => $s->type === SourceType::Asyncapi,
            );
            if ($asyncSources === []) {
                $syntheticSource = new SourceDescription('inline', $channelPath, SourceType::Asyncapi);
                $normalized = new NormalizedOpenApiOperation(
                    path: $channelPath,
                    method: 'POST',
                    resolvedServerUrl: null,
                    pathParameters: [],
                    queryParameters: [],
                    headerParameters: [],
                    cookieParameters: [],
                    requestBodies: [],
                    responses: [],
                );

                return new ResolvedOperation($syntheticSource, $normalized, operationName: $channelPath);
            }
        }

        [$sourceName, $channelReference] = $this->parseChannelReference($channelPath, $step->stepId, $document);

        $targetSource = $document->findSource($sourceName)
            ?? throw AsyncApiResolutionException::sourceNotFound($sourceName, $step->stepId);

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

        $resolved = $index[$channelReference]
            ?? $index['#'.$channelReference]
            ?? $index['#/channels/'.ltrim($channelReference, '/')]
            ?? $index[ltrim($channelReference, '#')]
            ?? $index[basename($channelReference)]
            ?? null;

        if ($resolved === null) {
            throw AsyncApiResolutionException::channelNotFound($channelReference, $sourceName);
        }

        return $resolved;
    }

    public function resolveTargetUrl(Step $step, ?WorkflowContext $context, ArazzoDocument $document): string
    {
        $channelPath = $step->target->channelPath ?? '';
        if (preg_match('#^https?://#i', $channelPath) === 1) {
            return $this->interpolatePath($channelPath, $step, $context, $document);
        }

        $resolved = $this->resolve($step, $document);
        $serverUrl = $resolved->normalized->resolvedServerUrl;
        $path = $this->interpolatePath($resolved->normalized->path, $step, $context, $document);

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
                $sourceName = substr($left, 21, -5);

                return [$sourceName, $right];
            }

            if ($left === '') {
                // Bare pointer, e.g. #/channels/orderPlaced
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
            static fn (SourceDescription $s): bool => $s->type === SourceType::Asyncapi,
        );

        return count($asyncSources) === 1 ? reset($asyncSources) : null;
    }

    private function interpolatePath(string $path, Step $step, ?WorkflowContext $context, ArazzoDocument $document): string
    {
        if (!str_contains($path, '{')) {
            return $path;
        }

        $parameters = new ReusableParameterResolver()->resolve($step->io->parameters, $document);
        if ($parameters === []) {
            return $path;
        }

        $evaluator = $this->evaluationEngine ?? new EvaluationEngine(expression: new ExpressionEngine());
        $evalContext = $context !== null ? new EvaluationContext($context, $step->stepId, $document) : null;

        $replacements = [];
        foreach ($parameters as $parameter) {
            $val = $parameter->value;
            if ($val instanceof Expression && $evalContext !== null) {
                $val = $evaluator->evaluate($val, $evalContext);
            }

            $stringVal = is_scalar($val) || $val instanceof \Stringable ? (string) $val : '';
            $replacements['{'.$parameter->name.'}'] = $stringVal;
        }

        return strtr($path, $replacements);
    }
}
