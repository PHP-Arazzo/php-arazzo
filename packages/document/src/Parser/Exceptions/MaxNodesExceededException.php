<?php

declare(strict_types=1);

namespace Alama\Arazzo\Document\Parser\Exceptions;

use Throwable;

/**
 * @internal stays out of the advertised contract; not part of the public API surface
 */
final class MaxNodesExceededException extends DecodeLimitException
{
    public function __construct(
        int $actualNodes,
        int $maxNodes,
        string $codeId = 'document.yaml_limit',
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            message: "Document node count of {$actualNodes} exceeds maxNodes limit of {$maxNodes} [{$codeId}].",
            limit: 'maxNodes',
            codeId: $codeId,
            code: 0,
            previous: $previous,
        );
    }
}
