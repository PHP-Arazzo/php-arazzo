<?php

declare(strict_types=1);

namespace Alama\Arazzo\Contracts\Discovery;

use Composer\InstalledVersions;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Scans installed Composer packages' `extra.arazzo.plugins` directories/entries
 * to collect candidate attribute-annotated plugin class names.
 *
 * @internal stays out of the advertised contract; not part of the public API surface
 */
final class PluginManifestScanner
{
    /**
     * @return list<class-string>
     */
    public static function scan(): array
    {
        $classes = [];
        foreach (self::manifestPaths() as $manifestPath) {
            foreach (self::readPluginsFromManifest($manifestPath) as $class) {
                $classes[$class] = true;
            }
        }

        /** @var list<class-string> $result */
        $result = array_keys($classes);
        sort($result);

        return $result;
    }

    /**
     * @return list<string>
     */
    private static function manifestPaths(): array
    {
        if (!class_exists(InstalledVersions::class)) {
            return [];
        }

        $paths = [];
        $packages = InstalledVersions::getInstalledPackages();
        sort($packages);

        foreach ($packages as $package) {
            $manifest = self::resolveManifest(InstalledVersions::getInstallPath($package));
            if ($manifest !== null) {
                $paths[$manifest] = true;
            }
        }

        $root = InstalledVersions::getRootPackage();
        $rootManifest = self::resolveManifest($root['install_path']);
        if ($rootManifest !== null) {
            $paths[$rootManifest] = true;
        }

        return array_keys($paths);
    }

    private static function resolveManifest(?string $installPath): ?string
    {
        if ($installPath === null || $installPath === '') {
            return null;
        }

        $manifest = $installPath.'/composer.json';
        if (!is_file($manifest)) {
            return null;
        }

        return realpath($manifest) ?: $manifest;
    }

    /**
     * @return list<class-string>
     */
    private static function readPluginsFromManifest(string $manifestPath): array
    {
        $entries = self::extractPluginEntries($manifestPath);
        if ($entries === []) {
            return [];
        }

        $packageDir = dirname($manifestPath);
        $classes = [];

        foreach ($entries as $entry) {
            foreach (self::resolveEntry($packageDir, $entry) as $class) {
                $classes[] = $class;
            }
        }

        return $classes;
    }

    /**
     * @return list<string>
     */
    private static function extractPluginEntries(string $manifestPath): array
    {
        $raw = @file_get_contents($manifestPath);
        if (!is_string($raw) || !str_contains($raw, '"arazzo"')) {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $extra = $decoded['extra'] ?? null;
        if (!is_array($extra)) {
            return [];
        }

        $arazzo = $extra['arazzo'] ?? null;
        if (!is_array($arazzo)) {
            return [];
        }

        $plugins = $arazzo['plugins'] ?? null;
        if (!is_array($plugins)) {
            return [];
        }

        return array_values(array_filter(
            $plugins,
            static fn (mixed $entry): bool => is_string($entry) && $entry !== '',
        ));
    }

    /**
     * @return list<class-string>
     */
    private static function resolveEntry(string $packageDir, string $entry): array
    {
        $candidatePath = $packageDir.'/'.ltrim($entry, '/');
        if (is_dir($candidatePath)) {
            return self::scanDirectory($candidatePath);
        }

        if (is_file($candidatePath) && str_ends_with($candidatePath, '.php')) {
            $class = self::extractAttributedClass($candidatePath);

            return $class !== null ? [$class] : [];
        }

        if (str_contains($entry, '\\')) {
            /** @var class-string $entry */
            return [$entry];
        }

        return [];
    }

    /**
     * @return list<class-string>
     */
    private static function scanDirectory(string $directory): array
    {
        $classes = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $class = self::extractAttributedClass($file->getPathname());
            if ($class !== null) {
                $classes[] = $class;
            }
        }

        return $classes;
    }

    /**
     * @return class-string|null
     */
    private static function extractAttributedClass(string $filePath): ?string
    {
        $code = @file_get_contents($filePath);
        if (!is_string($code) || preg_match('/^#\[As[A-Z]\w*/m', $code) !== 1) {
            return null;
        }

        if (preg_match('/^namespace\s+([^;]+);/m', $code, $ns) !== 1) {
            return null;
        }

        if (preg_match('/^(?:final\s+)?(?:readonly\s+)?class\s+(\w+)/m', $code, $cl) !== 1) {
            return null;
        }

        /** @var class-string $fqcn */
        $fqcn = trim($ns[1]).'\\'.$cl[1];

        return $fqcn;
    }
}
