<?php

declare(strict_types=1);

namespace Alama\Arazzo\Protocol\Http\Execution\Interfaces;

use Alama\Arazzo\Contracts\Policy\SourcePolicy;
use Alama\Arazzo\Contracts\Spec\OpenApiPayload;
use Alama\Arazzo\Protocol\Http\Resolver\OpenApiOperationHandle;
use Psr\Http\Message\ResponseInterface;

/**
 * @internal stays out of the advertised contract; not part of the public API surface
 */
interface OpenApiExecutorInterface
{
    public function execute(
        OpenApiOperationHandle $operation,
        OpenApiPayload $payload,
        ?callable $requestInterceptor = null,
        ?float $timeoutSeconds = null,
        ?SourcePolicy $sourcePolicy = null,
    ): ResponseInterface;
}
