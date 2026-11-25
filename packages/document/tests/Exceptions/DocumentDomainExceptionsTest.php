<?php

declare(strict_types=1);

use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Components;
use Alama\Arazzo\Contracts\Spec\Info;
use Alama\Arazzo\Contracts\Spec\Workflow;
use Alama\Arazzo\Document\Parser\Decoders\NativeJsonDecoder;
use Alama\Arazzo\Document\Parser\Exceptions\CustomTagsForbiddenException;
use Alama\Arazzo\Document\Parser\Exceptions\DecodeException;
use Alama\Arazzo\Document\Parser\Exceptions\MaxAliasesExceededException;
use Alama\Arazzo\Document\Parser\Exceptions\MaxBytesExceededException;
use Alama\Arazzo\Document\Parser\Exceptions\MaxDepthExceededException;
use Alama\Arazzo\Document\Parser\Exceptions\MaxNodesExceededException;
use Alama\Arazzo\Document\Parser\Exceptions\ObjectDeserializationForbiddenException;
use Alama\Arazzo\Document\Parser\Exceptions\UnsupportedSerializationStyleException;
use Alama\Arazzo\Document\Validator\RuleSet;
use Alama\Arazzo\Document\Validator\Validator;

test('document parser domain exception factories create typed exceptions', function (): void {
    $previous = new RuntimeException('boom');

    expect(DecodeException::fromThrowable($previous))
        ->toBeInstanceOf(DecodeException::class)
        ->and(DecodeException::fromThrowable($previous)->getMessage())->toBe('boom')
        ->and(CustomTagsForbiddenException::forTag('!custom')->getMessage())->toContain('!custom')
        ->and(MaxAliasesExceededException::exceeded(5, 3)->getMessage())->toContain('5')
        ->and(MaxBytesExceededException::exceeded(200, 100)->getMessage())->toContain('200')
        ->and(MaxDepthExceededException::exceeded(12, 10, $previous)->getMessage())->toContain('12')
        ->and(MaxNodesExceededException::exceeded(50, 20)->getMessage())->toContain('50')
        ->and(ObjectDeserializationForbiddenException::detected())->toBeInstanceOf(ObjectDeserializationForbiddenException::class)
        ->and(UnsupportedSerializationStyleException::forStyle('pipe', 'query')->getMessage())
        ->toContain('Unsupported serialization style "pipe" for location "query"');
});

test('NativeJsonDecoder wraps invalid JSON in DecodeException', function (): void {
    $decoder = new NativeJsonDecoder();

    expect(fn () => $decoder->decode('{invalid'))
        ->toThrow(DecodeException::class);
});

test('Validator::validateInputs handles workflows without inputs schema or non-object schemas', function (): void {
    $noInputs = new Workflow('wf-empty', null, null, null, [], [], [], [], [], []);
    $listSchema = new Workflow('wf-list', null, null, ['not', 'an', 'object'], [], [], [], [], [], []);

    $doc = new ArazzoDocument(
        arazzo: '1.0.1',
        info: new Info(title: 'Test', summary: null, description: null, version: '1.0.0'),
        sourceDescriptions: [],
        workflows: [$noInputs, $listSchema],
        components: new Components([], [], [], []),
        specificationExtensions: [],
    );

    $validator = new Validator(RuleSet::default());

    expect($validator->validateInputs($doc, 'wf-empty', ['a' => 1])->isValid())->toBeTrue()
        ->and($validator->validateInputs($doc, 'wf-list', ['a' => 1])->isValid())->toBeTrue();
});
