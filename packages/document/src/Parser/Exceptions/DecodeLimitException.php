<?php

declare(strict_types=1);

namespace Alama\Arazzo\Document\Parser\Exceptions;

use Throwable;

/**
 * @internal stays out of the advertised contract; not part of the public API surface
 */
class DecodeLimitException extends DecodeException
{
    public function __construct(
        string $message,
        public readonly string $limit,
        public readonly string $codeId = 'document.yaml_limit',
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
