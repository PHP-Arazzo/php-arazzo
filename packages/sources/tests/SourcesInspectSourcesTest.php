<?php

declare(strict_types=1);

namespace Alama\Arazzo\Tests;

use Alama\Arazzo\Contracts\Data\SourceInspectionResult;
use Alama\Arazzo\Contracts\Data\SourceResolutionStatus;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Components;
use Alama\Arazzo\Contracts\Spec\Enum\SourceType;
use Alama\Arazzo\Contracts\Spec\Info;
use Alama\Arazzo\Contracts\Spec\SourceDescription;
use Alama\Arazzo\Sources\Sources;

function makeTestDoc(array $sources): ArazzoDocument
{
    return new ArazzoDocument(
        arazzo: '1.0.0',
        info: new Info('Test', null, null, '1.0.0'),
        sourceDescriptions: $sources,
        workflows: [],
        components: new Components([], [], [], []),
        specificationExtensions: [],
    );
}

it('resolves valid OpenAPI source matching declared type', function (): void {
    $file = __DIR__.'/fixtures/document/openapi30.yaml';
    $source = new SourceDescription('api', $file, SourceType::Openapi);
    $doc = makeTestDoc([$source]);

    $sources = Sources::create();
    $result = $sources->inspectSources($doc, __DIR__);

    expect($result)->toBeInstanceOf(SourceInspectionResult::class)
        ->and($result->resolutions->isResolved())->toBeTrue()
        ->and($result->resolutions->has('api'))->toBeTrue();

    $res = $result->resolutions->get('api');
    expect($res->isOk())->toBeTrue()
        ->and($res->status)->toBe(SourceResolutionStatus::Ok)
        ->and($res->detectedType)->toBe(SourceType::Openapi)
        ->and($result->knownOperations->covers('api'))->toBeTrue();
});

it('flags missing source as unreadable', function (): void {
    $source = new SourceDescription('missing', './non_existent_file.yaml', SourceType::Openapi);
    $doc = makeTestDoc([$source]);

    $sources = Sources::create();
    $result = $sources->inspectSources($doc, __DIR__);

    $res = $result->resolutions->get('missing');
    expect($res->isUnreadable())->toBeTrue()
        ->and($res->status)->toBe(SourceResolutionStatus::Unreadable)
        ->and($res->errorMessage)->not->toBeEmpty()
        ->and($result->knownOperations->covers('missing'))->toBeFalse();
});

it('flags unparseable source content as unparseable', function (): void {
    $tempDir = sys_get_temp_dir().'/arazzo_src_'.uniqid();
    mkdir($tempDir);
    $badFile = $tempDir.'/bad.yaml';
    file_put_contents($badFile, "key: [unclosed string\n");

    try {
        $source = new SourceDescription('bad', $badFile, SourceType::Openapi);
        $doc = makeTestDoc([$source]);

        $sources = Sources::create();
        $result = $sources->inspectSources($doc, $tempDir);

        $res = $result->resolutions->get('bad');
        expect($res->isUnparseable())->toBeTrue()
            ->and($res->status)->toBe(SourceResolutionStatus::Unparseable)
            ->and($res->errorMessage)->not->toBeEmpty();
    } finally {
        unlink($badFile);
        rmdir($tempDir);
    }
});

it('flags declared type mismatch against detected content', function (): void {
    $file = __DIR__.'/fixtures/document/openapi30.yaml';
    // openapi30.yaml has 'openapi: 3.0.0', but we declare type: arazzo
    $source = new SourceDescription('api', $file, SourceType::Arazzo);
    $doc = makeTestDoc([$source]);

    $sources = Sources::create();
    $result = $sources->inspectSources($doc, __DIR__);

    $res = $result->resolutions->get('api');
    expect($res->isTypeMismatch())->toBeTrue()
        ->and($res->status)->toBe(SourceResolutionStatus::TypeMismatch)
        ->and($res->declaredType)->toBe(SourceType::Arazzo)
        ->and($res->detectedType)->toBe(SourceType::Openapi);
});

it('infers type when omitted if content matches OpenAPI', function (): void {
    $file = __DIR__.'/fixtures/document/openapi30.yaml';
    $source = new SourceDescription('api', $file, null);
    $doc = makeTestDoc([$source]);

    $sources = Sources::create();
    $result = $sources->inspectSources($doc, __DIR__);

    $res = $result->resolutions->get('api');
    expect($res->isOk())->toBeTrue()
        ->and($res->status)->toBe(SourceResolutionStatus::Ok)
        ->and($res->detectedType)->toBe(SourceType::Openapi)
        ->and($result->knownOperations->covers('api'))->toBeTrue();
});

it('flags inference failed when type is omitted and content is unrecognizable', function (): void {
    $tempDir = sys_get_temp_dir().'/arazzo_src_'.uniqid();
    mkdir($tempDir);
    $unknownFile = $tempDir.'/unknown.yaml';
    file_put_contents($unknownFile, "title: Hello\nfoo: bar\n");

    try {
        $source = new SourceDescription('untyped', $unknownFile, null);
        $doc = makeTestDoc([$source]);

        $sources = Sources::create();
        $result = $sources->inspectSources($doc, $tempDir);

        $res = $result->resolutions->get('untyped');
        expect($res->isTypeInferenceFailed())->toBeTrue()
            ->and($res->status)->toBe(SourceResolutionStatus::TypeInferenceFailed)
            ->and($res->errorMessage)->not->toBeEmpty();
    } finally {
        unlink($unknownFile);
        rmdir($tempDir);
    }
});

it('inspects multiple sources with mixed outcomes without aborting', function (): void {
    $goodFile = __DIR__.'/fixtures/document/openapi30.yaml';
    $doc = makeTestDoc([
        new SourceDescription('good', $goodFile, SourceType::Openapi),
        new SourceDescription('missing', './not_there.yaml', SourceType::Openapi),
        new SourceDescription('mismatched', $goodFile, SourceType::Arazzo),
    ]);

    $sources = Sources::create();
    $result = $sources->inspectSources($doc, __DIR__);

    expect($result->resolutions->count())->toBe(3)
        ->and($result->resolutions->get('good')->isOk())->toBeTrue()
        ->and($result->resolutions->get('missing')->isUnreadable())->toBeTrue()
        ->and($result->resolutions->get('mismatched')->isTypeMismatch())->toBeTrue()
        ->and($result->knownOperations->covers('good'))->toBeTrue()
        ->and($result->knownOperations->covers('missing'))->toBeFalse()
        ->and($result->knownOperations->covers('mismatched'))->toBeFalse();
});
