<?php

declare(strict_types=1);

namespace Alama\Arazzo\Document\Parser\Exceptions;

use Throwable;

/**
 * @internal stays out of the advertised contract; not part of the public API surface
 */
final class MaxDepthExceededException extends DecodeLimitException
{
    public function __construct(
        int $actualDepth,
        int $maxDepth,
        string $codeId = 'document.yaml_limit',
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            message: "Document nesting depth of {$actualDepth} exceeds maxDepth limit of {$maxDepth} [{$codeId}].",
            limit: 'maxDepth',
            codeId: $codeId,
            code: 0,
            previous: $previous,
        );
    }

    public static function exceeded(int $actualDepth, int $maxDepth, ?Throwable $previous = null): self
    {
        return new self($actualDepth, $maxDepth, previous: $previous);
    }
}
