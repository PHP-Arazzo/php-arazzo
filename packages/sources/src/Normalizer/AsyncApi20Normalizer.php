<?php

declare(strict_types=1);

namespace Alama\Arazzo\Sources\Normalizer;

use Alama\Arazzo\Contracts\Spec\NormalizedOpenApiOperation;
use Alama\Arazzo\Sources\Normalizer\Interfaces\AsyncApiNormalizerInterface;

/**
 * Normalizes AsyncAPI 2.x documents into NormalizedOpenApiOperation objects.
 *
 * @internal stays out of the advertised contract; not part of the public API surface
 */
final class AsyncApi20Normalizer implements AsyncApiNormalizerInterface
{
    /**
     * @param  array<string, mixed>  $rawDocument
     * @return array<string, NormalizedOpenApiOperation>
     */
    public function normalizeChannels(array $rawDocument): array
    {
        $normalized = [];
        $channels = $rawDocument['channels'] ?? [];
        if (!is_array($channels)) {
            return [];
        }

        /** @var array<string, mixed> $servers */
        $servers = (isset($rawDocument['servers']) && is_array($rawDocument['servers']))
            ? $rawDocument['servers']
            : [];

        foreach ($channels as $channelPath => $channelData) {
            if (!is_array($channelData)) {
                continue;
            }

            $resolvedServer = AsyncApiServerUrlResolver::resolve($channelData, $servers);

            $normalized[(string) $channelPath] = new NormalizedOpenApiOperation(
                path: (string) $channelPath,
                method: 'POST',
                resolvedServerUrl: $resolvedServer,
                pathParameters: [],
                queryParameters: [],
                headerParameters: [],
                cookieParameters: [],
                requestBodies: [],
                responses: [],
            );
        }

        return $normalized;
    }
}
