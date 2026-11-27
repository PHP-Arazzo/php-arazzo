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
                /** @var array<string, mixed> $targetServer */
                $targetServer = $servers[$serverRef];

                return self::formatServerUrl($targetServer);
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
                $rawProtocol = (isset($server['protocol']) && is_string($server['protocol'])) ? $server['protocol'] : '';
                $protocol = strtolower($rawProtocol);
                if ($protocol === 'http' || $protocol === 'https') {
                    /** @var array<string, mixed> $server */
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
                /** @var array<string, mixed> $server */
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
        $rawUrl = self::extractRawUrl($server);
        $protocol = (isset($server['protocol']) && is_string($server['protocol']))
            ? strtolower($server['protocol'])
            : 'https';

        if (isset($server['variables']) && is_array($server['variables'])) {
            /** @var array<string, mixed> $variables */
            $variables = $server['variables'];
            $rawUrl = self::substituteVariables($rawUrl, $variables);
        }

        if (preg_match('#^https?://#i', $rawUrl) === 1) {
            return rtrim($rawUrl, '/');
        }

        return self::applyProtocolScheme($rawUrl, $protocol);
    }

    /**
     * @param  array<string, mixed>  $server
     */
    private static function extractRawUrl(array $server): string
    {
        $url = (isset($server['url']) && is_string($server['url']))
            ? $server['url']
            : ((isset($server['host']) && is_string($server['host'])) ? $server['host'] : '');

        $pathname = (isset($server['pathname']) && is_string($server['pathname']))
            ? $server['pathname']
            : '';

        if ($pathname !== '') {
            $url = rtrim($url, '/').'/'.ltrim($pathname, '/');
        }

        return $url;
    }

    private static function applyProtocolScheme(string $rawUrl, string $protocol): string
    {
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
            if (is_array($def) && isset($def['default']) && is_scalar($def['default'])) {
                $url = str_replace('{'.$name.'}', (string) $def['default'], $url);
            }
        }

        return $url;
    }
}
