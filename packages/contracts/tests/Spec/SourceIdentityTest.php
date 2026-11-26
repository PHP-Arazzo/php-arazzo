<?php

declare(strict_types=1);

namespace Alama\Arazzo\Tests\Spec;

use Alama\Arazzo\Contracts\Spec\Enum\SourceType;
use Alama\Arazzo\Contracts\Spec\SourceDocument;
use Alama\Arazzo\Contracts\Spec\SourceIdentity;

it('supports selfUri on SourceDocument and computes baseUri', function (): void {
    $docWithoutSelf = new SourceDocument(
        name: 'api',
        type: SourceType::Openapi,
        canonicalUri: 'https://example.com/api.json',
        content: ['openapi' => '3.0.0'],
    );

    expect($docWithoutSelf->selfUri)->toBeNull()
        ->and($docWithoutSelf->baseUri())->toBe('https://example.com/api.json');

    $docWithSelf = new SourceDocument(
        name: 'arazzo-doc',
        type: SourceType::Arazzo,
        canonicalUri: 'https://cdn.example.com/cache.arazzo.yaml',
        content: ['arazzo' => '1.1.0'],
        selfUri: 'https://specs.example.com/canonical.arazzo.yaml',
    );

    expect($docWithSelf->selfUri)->toBe('https://specs.example.com/canonical.arazzo.yaml')
        ->and($docWithSelf->baseUri())->toBe('https://specs.example.com/canonical.arazzo.yaml');
});

it('creates SourceIdentity with retrievalUri, selfUri and optional document', function (): void {
    $identity = new SourceIdentity(
        retrievalUri: 'https://cdn.example.com/cache.arazzo.yaml',
        selfUri: 'https://specs.example.com/canonical.arazzo.yaml',
    );

    expect($identity->retrievalUri)->toBe('https://cdn.example.com/cache.arazzo.yaml')
        ->and($identity->selfUri)->toBe('https://specs.example.com/canonical.arazzo.yaml')
        ->and($identity->document)->toBeNull()
        ->and($identity->hasSelf())->toBeTrue();

    $doc = new SourceDocument(
        name: 'doc',
        type: SourceType::Arazzo,
        canonicalUri: 'https://cdn.example.com/cache.arazzo.yaml',
        content: ['arazzo' => '1.1.0'],
        selfUri: 'https://specs.example.com/canonical.arazzo.yaml',
    );

    $identityWithDoc = new SourceIdentity(
        retrievalUri: 'https://cdn.example.com/cache.arazzo.yaml',
        selfUri: 'https://specs.example.com/canonical.arazzo.yaml',
        document: $doc,
    );

    expect($identityWithDoc->document)->toBe($doc);
});
