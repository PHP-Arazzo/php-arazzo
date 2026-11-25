<?php

declare(strict_types=1);

namespace Alama\Arazzo\Contracts\Exceptions;

use Alama\Arazzo\Contracts\Support\Exceptions\ArazzoException;

final class PluginInstantiationException extends ArazzoException
{
    public static function nonPublicConstructor(string $class, string $attributeClass): self
    {
        return new self(
            "Plugin '{$class}' decorated with #[{$attributeClass}] cannot be auto-instantiated: constructor is not public.",
            '',
            'plugin.non_public_constructor',
        );
    }

    public static function missingConstructorArguments(string $class, string $attributeClass): self
    {
        return new self(
            "Plugin '{$class}' decorated with #[{$attributeClass}] cannot be auto-instantiated: "
            .'constructor requires arguments without defaults and no zero-arg static create() factory exists.',
            '',
            'plugin.unresolvable_constructor_arguments',
        );
    }
}
