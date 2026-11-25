<?php

declare(strict_types=1);

namespace Alama\Arazzo\Engine\Exceptions;

/**
 * @internal stays out of the advertised contract; not part of the public API surface
 */
final class ParameterResolutionException extends EngineException
{
    public static function unresolvableReference(string $reference): self
    {
        return new self(
            "Unresolvable reusable parameter reference '{$reference}'.",
            '',
            'engine.unresolvable_parameter_reference',
        );
    }
}
