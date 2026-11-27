<?php

declare(strict_types=1);

namespace Alama\Arazzo\Sources\Normalizer;

use Alama\Arazzo\Contracts\Spec\NormalizedOpenApiOperation;
use Alama\Arazzo\Sources\Normalizer\Interfaces\AsyncApiNormalizerInterface;

/**
 * @internal stays out of the advertised contract; not part of the public API surface
 */
final class AsyncApi20Normalizer implements AsyncApiNormalizerInterface
{
    /**
     * @param  array<string, mixed>  $document
     * @return array<string, NormalizedOpenApiOperation>
     */
    public function normalizeChannels(array $document): array
    {
        /** @var array<string, mixed> $rawChannels */
        $rawChannels = $document['channels'] ?? [];
        /** @var array<string, mixed> $rawServers */
        $rawServers = $document['servers'] ?? [];

        $normalized = [];

        foreach ($rawChannels as $channelKey => $channelData) {
            $channelKey = (string) $channelKey;
            if (!is_array($channelData)) {
                continue;
            }

            $resolvedServerUrl = $this->resolveServerUrl($channelData, $rawServers);

            $operation = new NormalizedOpenApiOperation(
                path: $channelKey,
                method: 'POST',
                resolvedServerUrl: $resolvedServerUrl,
                pathParameters: [],
                queryParameters: [],
                headerParameters: [],
                cookieParameters: [],
                requestBodies: [],
                responses: [],
            );

            $normalized[$channelKey] = $operation;
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $channelData
     * @param  array<string, mixed>  $servers
     */
    private function resolveServerUrl(array $channelData, array $servers): ?string
    {
        if ($servers === []) {
            return null;
        }

        // 1. Channel-scoped server
        if (isset($channelData['servers']) && is_array($channelData['servers'])) {
            foreach ($channelData['servers'] as $serverRef) {
                $serverKey = is_string($serverRef) ? $serverRef : null;
                if ($serverKey !== null && isset($servers[$serverKey]) && is_array($servers[$serverKey])) {
                    return $this->formatServerUrl($servers[$serverKey]);
                }
            }
        }

        // 2. First HTTP/HTTPS server
        foreach ($servers as $server) {
            if (is_array($server)) {
                $protocol = strtolower((string) ($server['protocol'] ?? ''));
                if ($protocol === 'http' || $protocol === 'https') {
                    return $this->formatServerUrl($server);
                }
            }
        }

        // 3. First server regardless of protocol
        foreach ($servers as $server) {
            if (is_array($server)) {
                return $this->formatServerUrl($server);
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $server
     */
    private function formatServerUrl(array $server): string
    {
        $rawUrl = (string) ($server['url'] ?? '');
        $protocol = strtolower((string) ($server['protocol'] ?? 'https'));

        // Variable substitution if present
        if (isset($server['variables']) && is_array($server['variables'])) {
            foreach ($server['variables'] as $varName => $varDef) {
                if (is_array($varDef) && isset($varDef['default'])) {
                    $rawUrl = str_replace('{'.$varName.'}', (string) $varDef['default'], $rawUrl);
                }
            }
        }

        if (preg_match('#^https?://#i', $rawUrl) === 1) {
            return rtrim($rawUrl, '/');
        }

        // Strip non-http protocol scheme if present (e.g. mqtt://broker -> broker)
        if (str_contains($rawUrl, '://')) {
            $parts = explode('://', $rawUrl, 2);
            $rawUrl = $parts[1] ?? '';
        }

        $scheme = ($protocol === 'http' || $protocol === 'https') ? $protocol.'://' : 'https://';

        return rtrim($scheme.ltrim($rawUrl, '/'), '/');
    }
}
