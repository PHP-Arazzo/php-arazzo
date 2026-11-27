<?php

declare(strict_types=1);

namespace Alama\Arazzo\Contracts\Tests\Data;

use Alama\Arazzo\Contracts\Data\KnownOperations;
use Alama\Arazzo\Contracts\Data\SourceInspectionResult;
use Alama\Arazzo\Contracts\Data\SourceResolution;
use Alama\Arazzo\Contracts\Data\SourceResolutionCollection;
use Alama\Arazzo\Contracts\Data\SourceResolutionStatus;
use Alama\Arazzo\Contracts\Spec\Enum\SourceType;
use Alama\Arazzo\Contracts\Spec\SourceDocument;

it('creates Ok resolution with detected type', function (): void {
    $doc = new SourceDocument('api', SourceType::Openapi, 'https://example.com/api.yaml', ['openapi' => '3.0.0']);
    $res = SourceResolution::valid('api', 'https://example.com/api.yaml', SourceType::Openapi, $doc);

    expect($res->name)->toBe('api')
        ->and($res->url)->toBe('https://example.com/api.yaml')
        ->and($res->status)->toBe(SourceResolutionStatus::Ok)
        ->and($res->isOk())->toBeTrue()
        ->and($res->isValid())->toBeTrue()
        ->and($res->isUnreadable())->toBeFalse()
        ->and($res->isUnparseable())->toBeFalse()
        ->and($res->isTypeMismatch())->toBeFalse()
        ->and($res->isTypeInferenceFailed())->toBeFalse()
        ->and($res->detectedType)->toBe(SourceType::Openapi)
        ->and($res->document)->toBe($doc);
});

it('creates Unreadable resolution with error message', function (): void {
    $res = SourceResolution::unreadable('api', './missing.yaml', 'File not found');

    expect($res->name)->toBe('api')
        ->and($res->url)->toBe('./missing.yaml')
        ->and($res->status)->toBe(SourceResolutionStatus::Unreadable)
        ->and($res->isUnreadable())->toBeTrue()
        ->and($res->errorMessage)->toBe('File not found');
});

it('creates Unparseable resolution with error message', function (): void {
    $res = SourceResolution::unparseable('api', './bad.yaml', 'Invalid YAML syntax');

    expect($res->name)->toBe('api')
        ->and($res->url)->toBe('./bad.yaml')
        ->and($res->status)->toBe(SourceResolutionStatus::Unparseable)
        ->and($res->isUnparseable())->toBeTrue()
        ->and($res->errorMessage)->toBe('Invalid YAML syntax');
});

it('creates TypeMismatch resolution with declared and detected types', function (): void {
    $res = SourceResolution::typeMismatch('api', './openapi.yaml', SourceType::Arazzo, SourceType::Openapi);

    expect($res->name)->toBe('api')
        ->and($res->url)->toBe('./openapi.yaml')
        ->and($res->status)->toBe(SourceResolutionStatus::TypeMismatch)
        ->and($res->isTypeMismatch())->toBeTrue()
        ->and($res->declaredType)->toBe(SourceType::Arazzo)
        ->and($res->detectedType)->toBe(SourceType::Openapi);
});

it('creates TypeInferenceFailed resolution with error message', function (): void {
    $res = SourceResolution::typeInferenceFailed('api', './unknown.yaml', 'Unrecognized document format');

    expect($res->name)->toBe('api')
        ->and($res->url)->toBe('./unknown.yaml')
        ->and($res->status)->toBe(SourceResolutionStatus::TypeInferenceFailed)
        ->and($res->isTypeInferenceFailed())->toBeTrue()
        ->and($res->errorMessage)->toBe('Unrecognized document format');
});

it('manages SourceResolutionCollection immutably', function (): void {
    $empty = SourceResolutionCollection::empty();
    expect($empty->isResolved())->toBeFalse()
        ->and($empty->count())->toBe(0)
        ->and($empty->all())->toBe([])
        ->and($empty->has('api'))->toBeFalse()
        ->and($empty->get('api'))->toBeNull();

    $res1 = SourceResolution::valid('api', 'https://example.com/api.yaml', SourceType::Openapi);
    $res2 = SourceResolution::unreadable('auth', './auth.yaml', 'File not found');

    $collection = SourceResolutionCollection::fromArray([$res1, $res2], isResolved: true);

    expect($collection->isResolved())->toBeTrue()
        ->and($collection->count())->toBe(2)
        ->and($collection->has('api'))->toBeTrue()
        ->and($collection->has('auth'))->toBeTrue()
        ->and($collection->has('other'))->toBeFalse()
        ->and($collection->get('api'))->toBe($res1)
        ->and($collection->get('auth'))->toBe($res2);
});

it('encapsulates SourceInspectionResult', function (): void {
    $res = SourceResolution::valid('api', 'https://example.com/api.yaml', SourceType::Openapi);
    $collection = SourceResolutionCollection::fromArray([$res], isResolved: true);
    $known = new KnownOperations(['api' => ['getPet' => true]]);

    $inspection = new SourceInspectionResult($collection, $known);

    expect($inspection->resolutions)->toBe($collection)
        ->and($inspection->knownOperations)->toBe($known);
});
