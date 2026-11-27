<?php

declare(strict_types=1);

namespace Alama\Arazzo\Protocol\Rpc\Plugins\SourceNormalizer;

use Alama\Arazzo\Contracts\Attributes\AsSourceNormalizerPlugin;
use Alama\Arazzo\Contracts\Interfaces\SourceNormalizerInterface;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Enum\SourceType;
use Alama\Arazzo\Contracts\Spec\NormalizedOpenApiOperation;
use Alama\Arazzo\Contracts\Spec\ResolvedOperation;
use Alama\Arazzo\Contracts\Spec\SourceDescription;
use Alama\Arazzo\Protocol\Rpc\Normalizer\ProtoDocument;
use Alama\Arazzo\Protocol\Rpc\Normalizer\ProtoField;
use Alama\Arazzo\Protocol\Rpc\Normalizer\ProtoMethod;
use Alama\Arazzo\Protocol\Rpc\Normalizer\ProtoParser;
use Alama\Arazzo\Protocol\Rpc\Normalizer\ProtoService;

/**
 * Port (D2): the RPC sibling of OpenApiSourceNormalizer and
 * WsdlSourceNormalizer — turns a described protobuf source + raw `.proto`
 * content into a ResolvedOperation index for the source registry. Ships in
 * protocol-rpc because `.proto` reading is the DF11/D5 vendored-codec
 * exception: it never moves to arazzo-sources.
 *
 * Index keys mirror the RPC source-qualified reference grammar
 * (`$sourceDescriptions.<name>.<Service>/<Method>`, plain `<Service>/<Method>`
 * for a single source), matching ProtobufOperationResolver's accepted
 * references.
 *
 * rpcProtocol is a step-object property (spec PR #556), so the index carries
 * it as null and ProtobufOperationResolver re-wraps the operation with the
 * step's discriminator at resolve time.
 *
 * @internal stays out of the advertised contract; not part of the public API surface
 */
#[AsSourceNormalizerPlugin(SourceType::Protobuf)]
final class ProtoSourceNormalizer implements SourceNormalizerInterface
{
    public function name(): string
    {
        return 'protobuf';
    }

    public function priority(): int
    {
        return 0;
    }

    public function supports(SourceType $type): bool
    {
        return $type === SourceType::Protobuf;
    }

    /**
     * @return array<string, ResolvedOperation>
     */
    public function normalize(SourceDescription $source, string $rawContent, ?ArazzoDocument $document = null): array
    {
        $parsed = (new ProtoParser())->parse($rawContent);

        $index = [];
        foreach ($parsed->services as $service) {
            foreach ($service->methods as $method) {
                $reference = $service->methodReference($method);
                $resolved = $this->resolveOperation($source, $parsed, $service, $method);
                $index[$reference] = $resolved;
                $index['$sourceDescriptions.'.$source->name.'.'.$reference] = $resolved;
            }
        }

        return $index;
    }

    private function resolveOperation(
        SourceDescription $source,
        ProtoDocument $parsed,
        ProtoService $service,
        ProtoMethod $method,
    ): ResolvedOperation {
        $reference = $service->methodReference($method);
        $normalized = new NormalizedOpenApiOperation(
            path: $reference,
            method: 'POST',
            resolvedServerUrl: $source->url,
            requestBodies: [
                'application/protobuf' => [
                    'package' => $parsed->package,
                    'service' => $service->name,
                    'method' => $method->name,
                    'requestType' => $method->requestType,
                    'responseType' => $method->responseType,
                    'requestStreaming' => $method->requestStreaming,
                    'responseStreaming' => $method->responseStreaming,
                    'requestFields' => array_map(static fn (ProtoField $field): array => $field->toArray(), $parsed->messageFields($method->requestType)),
                    'responseFields' => array_map(static fn (ProtoField $field): array => $field->toArray(), $parsed->messageFields($method->responseType)),
                ],
            ],
            responses: [
                'application/protobuf' => [
                    'responseType' => $method->responseType,
                ],
            ],
        );

        return new ResolvedOperation($source, $normalized, rpcMethod: $reference);
    }
}
