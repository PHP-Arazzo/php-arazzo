<?php

declare(strict_types=1);

namespace Alama\Arazzo\Sources\Normalizer;

/**
 * Resolves and formats AsyncAPI server base URLs for channels.
 *
 * @internal
 */
final class AsyncApiServerUrlResolver
{
    /**
     * @param  array<string, mixed>  $channelData
     * @param  array<string, mixed>  $servers
     */
    public static function resolve(array $channelData, array $servers): ?string
    {
        if ($servers === []) {
            return null;
        }

        $scoped = self::resolveChannelScoped($channelData, $servers);
        if ($scoped !== null) {
            return $scoped;
        }

        $http = self::resolveHttpServer($servers);
        if ($http !== null) {
            return $http;
        }

        return self::resolveAnyServer($servers);
    }

    /**
     * @param  array<string, mixed>  $channelData
     * @param  array<string, mixed>  $servers
     */
    private static function resolveChannelScoped(array $channelData, array $servers): ?string
    {
        if (!isset($channelData['servers']) || !is_array($channelData['servers'])) {
            return null;
        }

        foreach ($channelData['servers'] as $serverRef) {
            if (is_string($serverRef) && isset($servers[$serverRef]) && is_array($servers[$serverRef])) {
                return self::formatServerUrl($servers[$serverRef]);
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $servers
     */
    private static function resolveHttpServer(array $servers): ?string
    {
        foreach ($servers as $server) {
            if (is_array($server)) {
                $protocol = strtolower((string) ($server['protocol'] ?? ''));
                if ($protocol === 'http' || $protocol === 'https') {
                    return self::formatServerUrl($server);
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $servers
     */
    private static function resolveAnyServer(array $servers): ?string
    {
        foreach ($servers as $server) {
            if (is_array($server)) {
                return self::formatServerUrl($server);
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $server
     */
    public static function formatServerUrl(array $server): string
    {
        $rawUrl = (string) ($server['url'] ?? $server['host'] ?? '');
        $protocol = strtolower((string) ($server['protocol'] ?? 'https'));

        $pathname = (string) ($server['pathname'] ?? '');
        if ($pathname !== '') {
            $rawUrl = rtrim($rawUrl, '/').'/'.ltrim($pathname, '/');
        }

        if (isset($server['variables']) && is_array($server['variables'])) {
            $rawUrl = self::substituteVariables($rawUrl, $server['variables']);
        }

        if (preg_match('#^https?://#i', $rawUrl) === 1) {
            return rtrim($rawUrl, '/');
        }

        $stripped = (string) preg_replace('#^[a-zA-Z0-9+.-]+://#', '', $rawUrl);
        $scheme = ($protocol === 'http') ? 'http://' : 'https://';

        return rtrim($scheme.$stripped, '/');
    }

    /**
     * @param  array<string, mixed>  $variables
     */
    private static function substituteVariables(string $url, array $variables): string
    {
        foreach ($variables as $name => $def) {
            if (is_array($def) && isset($def['default'])) {
                $url = str_replace('{'.$name.'}', (string) $def['default'], $url);
            }
        }

        return $url;
    }
}
