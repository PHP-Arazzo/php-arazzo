<?php

declare(strict_types=1);

namespace Alama\Arazzo\Sources\Normalizer;

use Alama\Arazzo\Contracts\Spec\NormalizedOpenApiOperation;
use Alama\Arazzo\Sources\Normalizer\Interfaces\AsyncApiNormalizerInterface;

/**
 * Normalizes AsyncAPI 3.x documents into NormalizedOpenApiOperation objects.
 *
 * @internal stays out of the advertised contract; not part of the public API surface
 */
final class AsyncApi30Normalizer implements AsyncApiNormalizerInterface
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

        foreach ($channels as $channelId => $channelData) {
            if (!is_array($channelData)) {
                continue;
            }

            /** @var array<string, mixed> $channelData */
            $address = (isset($channelData['address']) && is_string($channelData['address'])) ? $channelData['address'] : (string) $channelId;
            $resolvedServer = AsyncApiServerUrlResolver::resolve($channelData, $servers);

            $operation = new NormalizedOpenApiOperation(
                path: $address,
                method: 'POST',
                resolvedServerUrl: $resolvedServer,
                pathParameters: [],
                queryParameters: [],
                headerParameters: [],
                cookieParameters: [],
                requestBodies: [],
                responses: [],
            );

            $normalized[(string) $channelId] = $operation;
            if ($address !== (string) $channelId && !isset($normalized[$address])) {
                $normalized[$address] = $operation;
            }
        }

        return $normalized;
    }
}
