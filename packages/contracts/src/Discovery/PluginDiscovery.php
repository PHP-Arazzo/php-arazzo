<?php

declare(strict_types=1);

namespace Alama\Arazzo\Contracts\Discovery;

use ReflectionClass;

/**
 * Discovers attribute-annotated Arazzo plugins declared in installed packages'
 * composer.json `extra.arazzo.plugins` directories (or explicit class names)
 * via Composer\InstalledVersions.
 *
 * Works in any framework-agnostic PHP runtime without scanning all of vendor/.
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
     * @param  class-string  $attributeClass
     * @param  list<object>  $services
     * @return list<object>
     */
    public static function instantiate(string $attributeClass, array $services = []): array
    {
        $instances = [];
        foreach (self::classesWith($attributeClass) as $class) {
            $instances[] = PluginInstantiator::instantiate($class, $attributeClass, $services);
        }

        return $instances;
    }

    /**
     * Returns all unique class names discovered across installed packages'
     * `extra.arazzo.plugins` declarations.
     *
     * @return list<class-string>
     */
    public static function allClasses(): array
    {
        if (self::$overrideClasses !== null) {
            return self::$overrideClasses;
        }

        return self::$discoveredClasses ??= PluginManifestScanner::scan();
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
}
