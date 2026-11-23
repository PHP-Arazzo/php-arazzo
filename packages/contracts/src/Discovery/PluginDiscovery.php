<?php

declare(strict_types=1);

namespace Alama\Arazzo\Contracts\Discovery;

use Composer\InstalledVersions;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;
use RuntimeException;

/**
 * Discovers attribute-annotated Arazzo plugins declared in installed packages'
 * composer.json `extra.arazzo.plugins` manifests via Composer\InstalledVersions.
 *
 * Works in any framework-agnostic PHP runtime without filesystem scanning.
 */
final class PluginDiscovery
{
    /** @var list<class-string>|null */
    private static ?array $discoveredClasses = null;

    /** @var list<class-string>|null */
    private static ?array $overrideClasses = null;

    /**
     * Returns all declared plugin class names carrying the given PHP 8 attribute.
     *
     * @param  class-string  $attributeClass
     * @return list<class-string>
     */
    public static function classesWith(string $attributeClass): array
    {
        $matched = [];
        foreach (self::allClasses() as $class) {
            if (!class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);
            if ($reflection->getAttributes($attributeClass) !== []) {
                $matched[] = $class;
            }
        }

        return $matched;
    }

    /**
     * Instantiates all declared stateless plugins carrying the given PHP 8 attribute.
     *
     * Uses `static create()` when available with zero required parameters, otherwise
     * resolves constructor parameters against optional `$services` (by type) or
     * zero-arg `new $class()`. Throws loudly if any required constructor parameter
     * cannot be satisfied.
     *
     * @param  class-string  $attributeClass
     * @param  list<object>  $services
     * @return list<object>
     */
    public static function instantiate(string $attributeClass, array $services = []): array
    {
        $instances = [];
        foreach (self::classesWith($attributeClass) as $class) {
            $instances[] = self::instantiateClass($class, $attributeClass, $services);
        }

        return $instances;
    }

    /**
     * Returns all unique class names declared across installed packages'
     * `extra.arazzo.plugins` manifests.
     *
     * @return list<class-string>
     */
    public static function allClasses(): array
    {
        if (self::$overrideClasses !== null) {
            return self::$overrideClasses;
        }

        if (self::$discoveredClasses !== null) {
            return self::$discoveredClasses;
        }

        $classes = [];
        foreach (self::manifestPaths() as $manifestPath) {
            foreach (self::readPluginsFromManifest($manifestPath) as $class) {
                $classes[$class] = true;
            }
        }

        /** @var list<class-string> $result */
        $result = array_keys($classes);
        self::$discoveredClasses = $result;

        return $result;
    }

    /**
     * Override discovered classes (for isolated testing). Pass null to restore Composer discovery.
     *
     * @param  list<class-string>|null  $classes
     */
    public static function overrideClasses(?array $classes): void
    {
        self::$overrideClasses = $classes;
    }

    public static function reset(): void
    {
        self::$discoveredClasses = null;
        self::$overrideClasses = null;
    }

    /**
     * @param  class-string  $class
     * @param  class-string  $attributeClass
     * @param  list<object>  $services
     */
    private static function instantiateClass(string $class, string $attributeClass, array $services): object
    {
        $reflection = new ReflectionClass($class);

        if ($reflection->hasMethod('create')) {
            $create = $reflection->getMethod('create');
            if ($create->isPublic() && $create->isStatic() && $create->getNumberOfRequiredParameters() === 0) {
                $instance = $create->invoke(null);
                if (is_object($instance)) {
                    return $instance;
                }
            }
        }

        $constructor = $reflection->getConstructor();
        if ($constructor === null) {
            return $reflection->newInstance();
        }

        if (!$constructor->isPublic()) {
            throw new RuntimeException(
                "Plugin '{$class}' decorated with #[{$attributeClass}] cannot be auto-instantiated: constructor is not public.",
            );
        }

        $args = self::resolveConstructorArgs($constructor->getParameters(), $services, $class, $attributeClass);

        return $reflection->newInstanceArgs($args);
    }

    /**
     * @param  list<ReflectionParameter>  $parameters
     * @param  list<object>  $services
     * @param  class-string  $class
     * @param  class-string  $attributeClass
     * @return list<mixed>
     */
    private static function resolveConstructorArgs(
        array $parameters,
        array $services,
        string $class,
        string $attributeClass,
    ): array {
        $args = [];
        foreach ($parameters as $param) {
            $matched = self::matchService($param, $services);
            if ($matched !== null) {
                $args[] = $matched;

                continue;
            }

            if ($param->isDefaultValueAvailable()) {
                $args[] = $param->getDefaultValue();

                continue;
            }

            throw new RuntimeException(
                "Plugin '{$class}' decorated with #[{$attributeClass}] cannot be auto-instantiated: "
                .'constructor requires arguments without defaults and no zero-arg static create() factory exists.',
            );
        }

        return $args;
    }

    /**
     * @param  list<object>  $services
     */
    private static function matchService(ReflectionParameter $param, array $services): ?object
    {
        $type = $param->getType();
        if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
            return null;
        }

        $typeName = $type->getName();
        foreach ($services as $service) {
            if ($service instanceof $typeName) {
                return $service;
            }
        }

        return null;
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

        $classes = [];
        foreach ($plugins as $entry) {
            if (is_string($entry) && $entry !== '') {
                /** @var class-string $entry */
                $classes[] = $entry;
            }
        }

        return $classes;
    }
}
