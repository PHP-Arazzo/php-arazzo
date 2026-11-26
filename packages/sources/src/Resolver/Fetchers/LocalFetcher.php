<?php

declare(strict_types=1);

namespace Alama\Arazzo\Sources\Resolver\Fetchers;

use Alama\Arazzo\Sources\Resolver\Exceptions\SourceFetchException;
use Alama\Arazzo\Sources\Resolver\Interfaces\SourceFetcher;
use Alama\Arazzo\Sources\Resolver\UriReferenceResolver;

/**
 * @internal stays out of the advertised contract; not part of the public API surface
 */
final class LocalFetcher implements SourceFetcher
{
    public function fetch(string $urlOrPath, string $basePath): string
    {
        $resolved = UriReferenceResolver::resolve($urlOrPath, $basePath);

        // Convert file URI to local filesystem path
        $path = (string) preg_replace('#^file:///?([a-zA-Z]:)#i', '$1', $resolved);
        if ($path === $resolved) {
            $path = (string) preg_replace('#^file://#i', '', $resolved);
        }

        $content = is_file($path) ? @file_get_contents($path) : false;

        if ($content === false && is_file($resolved)) {
            $content = @file_get_contents($resolved);
        }

        if ($content === false) {
            $legacyPath = $this->isAbsolute($urlOrPath)
                ? $urlOrPath
                : rtrim($basePath, '/\\').'/'.ltrim($urlOrPath, '/\\');
            $content = is_file($legacyPath) ? @file_get_contents($legacyPath) : false;
        }

        if ($content === false) {
            throw SourceFetchException::fileReadFailed($path);
        }

        return $content;
    }

    private function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path);
    }
}
