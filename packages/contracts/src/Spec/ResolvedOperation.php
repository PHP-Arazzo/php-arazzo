<?php

declare(strict_types=1);

namespace Alama\Arazzo\Contracts\Spec;

use Alama\Arazzo\Contracts\Spec\Enum\RpcProtocol;
use Alama\Arazzo\Contracts\Spec\Enum\SourceType;

readonly class ResolvedOperation
{
    public function __construct(
        public SourceDescription $source,
        public NormalizedOpenApiOperation $normalized,
        public ?RpcProtocol $rpcProtocol = null,
        public ?string $operationName = null,
        public ?string $rpcMethod = null,
        public ?GraphQlOperation $graphqlOperation = null,
        public ?Interaction $interaction = null,
    ) {}

    public function sourceType(): SourceType
    {
        return $this->source->type;
    }

    public function binding(): string
    {
        if ($this->rpcProtocol !== null) {
            return $this->rpcProtocol->value;
        }

        return match ($this->source->type) {
            SourceType::Openapi => 'http',
            SourceType::Arazzo => 'arazzo',
            SourceType::Asyncapi => 'asyncapi',
            SourceType::Wsdl => 'soap',
            SourceType::Protobuf => 'protobuf',
            SourceType::Graphql => 'graphql',
        };
    }
}
