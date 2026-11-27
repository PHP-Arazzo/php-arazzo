<?php

declare(strict_types=1);

namespace Alama\Arazzo\Protocol\Http\Resolver;

use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Enum\SourceType;
use Alama\Arazzo\Contracts\Spec\NormalizedOpenApiOperation;
use Alama\Arazzo\Contracts\Spec\ResolvedOperation;
use Alama\Arazzo\Contracts\Spec\SourceDescription;

/**
 * Creates synthetic fallback operations for direct URLs or mock steps.
 *
 * @internal
 */
final class AsyncApiFallbackResolver
{
    public static function tryDirectHttp(string $channelPath): ?ResolvedOperation
    {
        if (preg_match('#^https?://#i', $channelPath) !== 1) {
            return null;
        }

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

    public static function tryInlineMock(string $channelPath, ArazzoDocument $document): ?ResolvedOperation
    {
        if (str_contains($channelPath, '#') || str_starts_with($channelPath, '$sourceDescriptions.')) {
            return null;
        }

        $asyncSources = array_filter(
            $document->sourceDescriptions,
            static fn (SourceDescription $s): bool => $s->type === SourceType::Asyncapi,
        );
        if ($asyncSources !== []) {
            return null;
        }

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
