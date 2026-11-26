<?php

declare(strict_types=1);

namespace Alama\Arazzo\Sources\Resolver;

use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;

/**
 * Resolves relative source references and $self URIs per RFC 3986 (Arazzo 1.1.0 §5.2).
 *
 * @internal stays out of the advertised contract; not part of the public API surface
 */
final readonly class UriReferenceResolver
{
    public static function resolve(string $target, string $basePathOrUri): string
    {
        $target = self::normalizePath(trim($target));
        $base = self::normalizePath(trim($basePathOrUri));

        if (self::isWindowsDrive($target)) {
            return 'file:///'.ltrim($target, '/');
        }

        $targetScheme = parse_url($target, PHP_URL_SCHEME);
        if (is_string($targetScheme) && strlen($targetScheme) > 1) {
            return $target;
        }

        if (self::isWindowsDrive($base)) {
            $base = 'file:///'.ltrim($base, '/');
        }

        $baseScheme = parse_url($base, PHP_URL_SCHEME);
        if (is_string($baseScheme) && strlen($baseScheme) > 1) {
            return (string) UriResolver::resolve(new Uri($base), new Uri($target));
        }

        return self::resolveLocalPath($base, $target);
    }

    private static function normalizePath(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);

        return (string) preg_replace('#^file://([a-zA-Z]:/)#i', 'file:///$1', $normalized);
    }

    private static function isWindowsDrive(string $path): bool
    {
        return preg_match('/^[a-zA-Z]:\//', $path) === 1;
    }

    private static function resolveLocalPath(string $base, string $target): string
    {
        if (str_starts_with($target, '/')) {
            return 'file://'.$target;
        }

        $baseDir = rtrim($base, '/');
        $path = $baseDir.'/'.$target;

        $parts = explode('/', $path);
        $resolved = [];
        foreach ($parts as $part) {
            if ($part === '.' || $part === '') {
                continue;
            }
            if ($part === '..') {
                array_pop($resolved);
            } else {
                $resolved[] = $part;
            }
        }

        return 'file:///'.implode('/', $resolved);
    }
}
