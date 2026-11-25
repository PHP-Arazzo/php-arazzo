<?php

declare(strict_types=1);

use Alama\Arazzo\Engine\Exceptions\EngineException;
use Alama\Arazzo\Engine\Exceptions\ExecutionException;
use Alama\Arazzo\Protocol\Rpc\Exceptions\ProtobufResolutionException;
use Alama\Arazzo\Protocol\Rpc\Exceptions\ProtoSyntaxException;
use Alama\Arazzo\Protocol\Rpc\Exceptions\RpcCodecException;
use Alama\Arazzo\Protocol\Rpc\Exceptions\RpcExecutionException;
use Alama\Arazzo\Protocol\Rpc\Normalizer\ProtoDocument;
use Alama\Arazzo\Protocol\Rpc\Normalizer\ProtoField;
use Alama\Arazzo\Protocol\Rpc\Normalizer\ProtoMethod;
use Alama\Arazzo\Protocol\Rpc\Normalizer\ProtoService;
use Alama\Arazzo\Protocol\Rpc\Transfer\RpcResponseTransfer;

test('protocol-rpc domain exception factories construct typed exceptions', function (): void {
    $cause = new RuntimeException('boom');

    expect(RpcExecutionException::resolutionFailed('s1', $cause))
        ->toBeInstanceOf(ExecutionException::class)
        ->and(RpcExecutionException::protocolMissing('s1')->getMessage())->toContain('rpcProtocol')
        ->and(RpcExecutionException::requestBodyMissing('s1')->getMessage())->toContain('application/protobuf')
        ->and(RpcExecutionException::targetEndpointMissing('s1')->getMessage())->toContain('endpoint')
        ->and(ProtobufResolutionException::notProtobufStep('s1'))->toBeInstanceOf(EngineException::class)
        ->and(ProtobufResolutionException::sourceNotFound('rpc')->getMessage())->toContain('rpc')
        ->and(ProtobufResolutionException::methodNotFound('Svc/Call', 'rpc')->getMessage())->toContain('Svc/Call')
        ->and(RpcCodecException::unsupportedWireType(3))->toBeInstanceOf(EngineException::class)
        ->and(RpcCodecException::nonScalarFieldValue('items')->getMessage())->toContain('items')
        ->and(RpcCodecException::unsupportedFieldTypeFor('group', 'f')->getMessage())->toContain('group')
        ->and(RpcCodecException::unsupportedFieldType('group')->getMessage())->toContain('group')
        ->and(ProtoSyntaxException::expectedWord('syntax'))->toBeInstanceOf(InvalidArgumentException::class)
        ->and(ProtoSyntaxException::expectedSymbol(';')->getMessage())->toContain(';')
        ->and(ProtoSyntaxException::expectedStringLiteral()->getMessage())->toContain('string')
        ->and(ProtoSyntaxException::expectedFieldNumber()->getMessage())->toContain('field number')
        ->and(ProtoSyntaxException::notProto3Document()->getMessage())->toContain('proto3')
        ->and(ProtoSyntaxException::unsupportedSyntax('proto2')->getMessage())->toContain('proto2');
});

test('protocol-rpc Data and Transfer objects host domain queries directly (Tell, Dont Ask)', function (): void {
    $ok = new RpcResponseTransfer(200, [], 'raw-bytes');
    $rpcErr = new RpcResponseTransfer('not_found', [], null, [], ['rpc.error' => 'not_found']);
    $transportErr = new RpcResponseTransfer(500, [], '', [], ['error' => 'timeout']);

    expect($ok->hasFailure())->toBeFalse()
        ->and($ok->failureCategory())->toBeNull()
        ->and($ok->httpStatusCode())->toBe(200)
        ->and($ok->rawPayload())->toBe('raw-bytes')
        ->and($rpcErr->hasFailure())->toBeTrue()
        ->and($rpcErr->failureCategory())->toBe('rpc-error')
        ->and($rpcErr->httpStatusCode())->toBe(500)
        ->and($rpcErr->rawPayload())->toBeNull()
        ->and($transportErr->hasFailure())->toBeTrue()
        ->and($transportErr->failureCategory())->toBe('transport');

    $field = new ProtoField('id', 'string', 1, false);
    $method = new ProtoMethod('GetPet', 'GetPetReq', 'GetPetRes', false, false);
    $service = new ProtoService('PetService', [$method]);
    $doc = new ProtoDocument('pets.v1', [$service], ['GetPetReq' => [$field]]);

    expect($field->toArray())->toBe(['name' => 'id', 'type' => 'string', 'number' => 1, 'repeated' => false])
        ->and($service->methodReference($method))->toBe('PetService/GetPet')
        ->and($doc->messageFields('GetPetReq'))->toBe([$field])
        ->and($doc->messageFields('Missing'))->toBe([]);
});
