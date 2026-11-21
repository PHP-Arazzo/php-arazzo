<?php

declare(strict_types=1);

namespace Alama\Arazzo\Tests\Parser\Decoders;

use Alama\Arazzo\Document\Parser\DecodePolicy;
use Alama\Arazzo\Document\Parser\Decoders\NativeJsonDecoder;
use Alama\Arazzo\Document\Parser\Exceptions\DecodeException;
use Alama\Arazzo\Document\Parser\Exceptions\DecodeLimitException;
use Alama\Arazzo\Document\Parser\Exceptions\MaxBytesExceededException;
use Alama\Arazzo\Document\Parser\Exceptions\MaxDepthExceededException;
use Alama\Arazzo\Document\Parser\Exceptions\MaxNodesExceededException;

it('rejects JSON documents over max bytes before decoding', function (): void {
    $policy = DecodePolicy::default()->withMaxBytes(50);
    $decoder = new NativeJsonDecoder($policy);

    $oversizedContent = json_encode(['data' => str_repeat('a', 100)]);

    expect(fn () => $decoder->decode($oversizedContent))
        ->toThrow(function (MaxBytesExceededException $e): void {
            expect($e)->toBeInstanceOf(DecodeException::class)
                ->and($e)->toBeInstanceOf(DecodeLimitException::class)
                ->and($e->limit)->toBe('maxBytes')
                ->and($e->codeId)->toBe('document.yaml_limit')
                ->and($e->getMessage())->toContain('maxBytes');
        });
});

it('rejects depth over max in JSON', function (): void {
    $policy = DecodePolicy::default()->withMaxDepth(3);
    $decoder = new NativeJsonDecoder($policy);

    $json = '{"a":{"b":{"c":{"d":1}}}}';

    expect(fn () => $decoder->decode($json))
        ->toThrow(function (MaxDepthExceededException $e): void {
            expect($e)->toBeInstanceOf(DecodeException::class)
                ->and($e)->toBeInstanceOf(DecodeLimitException::class)
                ->and($e->limit)->toBe('maxDepth')
                ->and($e->codeId)->toBe('document.yaml_limit')
                ->and($e->getMessage())->toContain('maxDepth');
        });
});

it('rejects nodes over max in JSON', function (): void {
    $policy = DecodePolicy::default()->withMaxNodes(3);
    $decoder = new NativeJsonDecoder($policy);

    $json = '{"items":[1,2,3,4]}';

    expect(fn () => $decoder->decode($json))
        ->toThrow(function (MaxNodesExceededException $e): void {
            expect($e)->toBeInstanceOf(DecodeException::class)
                ->and($e)->toBeInstanceOf(DecodeLimitException::class)
                ->and($e->limit)->toBe('maxNodes')
                ->and($e->codeId)->toBe('document.yaml_limit')
                ->and($e->getMessage())->toContain('maxNodes');
        });
});
