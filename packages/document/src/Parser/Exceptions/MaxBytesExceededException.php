<?php

declare(strict_types=1);

namespace Alama\Arazzo\Document\Parser\Exceptions;

use Throwable;

/**
 * @internal stays out of the advertised contract; not part of the public API surface
 */
final class MaxBytesExceededException extends DecodeLimitException
{
    public function __construct(
        int $actualBytes,
        int $maxBytes,
        string $codeId = 'document.yaml_limit',
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            message: "Document size of {$actualBytes} bytes exceeds maxBytes limit of {$maxBytes} bytes [{$codeId}].",
            limit: 'maxBytes',
            codeId: $codeId,
            code: 0,
            previous: $previous,
        );
    }

    public static function exceeded(int $actualBytes, int $maxBytes): self
    {
        return new self($actualBytes, $maxBytes);
    }
}
