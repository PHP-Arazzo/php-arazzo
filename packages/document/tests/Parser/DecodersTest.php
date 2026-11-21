<?php

declare(strict_types=1);

namespace Alama\Arazzo\Tests\Parser;

use Alama\Arazzo\Contracts\Spec\Enum\Format;
use Alama\Arazzo\Document\Parser\DecodePolicy;
use Alama\Arazzo\Document\Parser\Decoders;
use Alama\Arazzo\Document\Parser\Interfaces\DecoderInterface;

it('creates a decoder for JSON format', function (): void {
    $decoder = Decoders::for(Format::Json);

    expect($decoder)->toBeInstanceOf(DecoderInterface::class);
    $data = $decoder->decode('{"title":"test"}');
    expect($data)->toBe(['title' => 'test']);
});

it('creates a decoder for YAML format', function (): void {
    $decoder = Decoders::for(Format::Yaml);

    expect($decoder)->toBeInstanceOf(DecoderInterface::class);
    $data = $decoder->decode("title: test\n");
    expect($data)->toBe(['title' => 'test']);
});

it('passes custom decode policy to decoders', function (): void {
    $policy = DecodePolicy::default()->withMaxBytes(10);
    $decoder = Decoders::for(Format::Json, $policy);

    expect(fn () => $decoder->decode('{"very_long_key": "very_long_value"}'))
        ->toThrow(\Alama\Arazzo\Document\Parser\Exceptions\MaxBytesExceededException::class);
});
