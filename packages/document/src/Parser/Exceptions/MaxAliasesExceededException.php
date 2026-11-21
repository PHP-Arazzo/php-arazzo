<?php

declare(strict_types=1);

namespace Alama\Arazzo\Document\Parser\Exceptions;

use Throwable;

/**
 * @internal stays out of the advertised contract; not part of the public API surface
 */
final class MaxAliasesExceededException extends DecodeLimitException
{
    public function __construct(
        int $actualAliases,
        int $maxAliases,
        string $codeId = 'document.yaml_limit',
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            message: "Document alias count or fan-out of {$actualAliases} exceeds maxAliases limit of {$maxAliases} [{$codeId}].",
            limit: 'maxAliases',
            codeId: $codeId,
            code: 0,
            previous: $previous,
        );
    }
}
