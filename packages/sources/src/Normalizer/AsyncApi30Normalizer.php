<?php

declare(strict_types=1);

namespace Alama\Arazzo\Sources\Normalizer;

use Alama\Arazzo\Contracts\Spec\NormalizedOpenApiOperation;
use Alama\Arazzo\Sources\Normalizer\Interfaces\AsyncApiNormalizerInterface;

/**
 * @internal stays out of the advertised contract; not part of the public API surface
 */
final class AsyncApi30Normalizer implements AsyncApiNormalizerInterface
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

            $address = isset($channelData['address']) && is_string($channelData['address']) && $channelData['address'] !== ''
                ? $channelData['address']
                : $channelKey;

            $resolvedServerUrl = $this->resolveServerUrl($channelData, $rawServers);

            $operation = new NormalizedOpenApiOperation(
                path: $address,
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
            if ($address !== $channelKey) {
                $normalized[$address] = $operation;
            }
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
                $serverKey = null;
                if (is_string($serverRef)) {
                    $serverKey = str_contains($serverRef, '/') ? basename($serverRef) : $serverRef;
                } elseif (is_array($serverRef) && isset($serverRef['$ref']) && is_string($serverRef['$ref'])) {
                    $serverKey = basename($serverRef['$ref']);
                }

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
        $host = (string) ($server['host'] ?? '');
        $pathname = (string) ($server['pathname'] ?? '');
        $protocol = strtolower((string) ($server['protocol'] ?? 'https'));

        // Variable substitution in host and pathname if present
        if (isset($server['variables']) && is_array($server['variables'])) {
            foreach ($server['variables'] as $varName => $varDef) {
                if (is_array($varDef) && isset($varDef['default'])) {
                    $host = str_replace('{'.$varName.'}', (string) $varDef['default'], $host);
                    $pathname = str_replace('{'.$varName.'}', (string) $varDef['default'], $pathname);
                }
            }
        }

        if (preg_match('#^https?://#i', $host) === 1) {
            $base = rtrim($host, '/');
            if ($pathname !== '') {
                $base .= '/'.ltrim($pathname, '/');
            }

            return rtrim($base, '/');
        }

        if (str_contains($host, '://')) {
            $parts = explode('://', $host, 2);
            $host = $parts[1] ?? '';
        }

        $scheme = ($protocol === 'http' || $protocol === 'https') ? $protocol.'://' : 'https://';
        $target = $scheme.rtrim(ltrim($host, '/'), '/');
        if ($pathname !== '') {
            $target .= '/'.ltrim($pathname, '/');
        }

        return rtrim($target, '/');
    }
}
