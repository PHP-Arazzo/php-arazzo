<?php

declare(strict_types=1);

namespace Alama\Arazzo\Contracts\Discovery;

use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;
use RuntimeException;

/**
 * Instantiates stateless attribute-annotated plugin classes via zero-arg
 * `static create()`, type-matched service injection, or zero-arg constructor.
 *
 * @internal stays out of the advertised contract; not part of the public API surface
 */
final class PluginInstantiator
{
    /**
     * @param  class-string  $class
     * @param  class-string  $attributeClass
     * @param  list<object>  $services
     */
    public static function instantiate(string $class, string $attributeClass, array $services = []): object
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
}
