<?php

declare(strict_types=1);

namespace Alama\Arazzo\Sources\Normalizer;

use Alama\Arazzo\Document\ResolvedOperation;
use cebe\openapi\spec\OpenApi;
use cebe\openapi\spec\Operation;

/**
 * The cebe-facing view of a resolved operation. The three OpenAPI-specific
 * consumers in the runner receive this instead of the model type, which is
 * what lets alama/arazzo-document stay cebe-free.
 */
final class OpenApiOperationHandle extends ResolvedOperation
{
    public readonly ResolvedOperation $operation;

    public function __construct(
        ResolvedOperation $operation,
        public readonly OpenApi $openApi,
        public readonly Operation $cebeOperation,
    ) {
        parent::__construct(
            source: $operation->source,
            normalized: $operation->normalized,
            rpcProtocol: $operation->rpcProtocol,
            operationName: $operation->operationName,
            rpcMethod: $operation->rpcMethod,
            graphqlOperation: $operation->graphqlOperation,
            interaction: $operation->interaction,
        );
        $this->operation = $operation;
    }
}
