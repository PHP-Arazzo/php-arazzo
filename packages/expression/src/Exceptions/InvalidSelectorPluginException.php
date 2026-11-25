<?php

declare(strict_types=1);

namespace Alama\Arazzo\Expression\Exceptions;

use InvalidArgumentException;

final class InvalidSelectorPluginException extends InvalidArgumentException
{
    public static function missingPluginInstance(): self
    {
        return new self('Plugin must be provided when registering by ExpressionType.');
    }

    public static function undeterminableType(): self
    {
        return new self(
            'Cannot determine ExpressionType for selector plugin: missing #[AsSelectorPlugin] attribute or supports() matching.',
        );
    }
}
