<?php

declare(strict_types=1);

namespace Alama\Arazzo\Tests\Resolver;

use Alama\Arazzo\Contracts\Spec\Enum\SourceType;
use Alama\Arazzo\Contracts\Spec\SourceDocument;
use Alama\Arazzo\Contracts\Spec\SourceIdentity;
use Alama\Arazzo\Sources\Exceptions\SourceResolutionException;
use Alama\Arazzo\Sources\Resolver\SourceIdentityCollection;

it('resolves by self before location (S2)', function (): void {
    $doc = new SourceDocument(
        name: 'copy',
        type: SourceType::Arazzo,
        canonicalUri: 'https://cache.example.com/copy.arazzo',
        content: ['arazzo' => '1.1.0'],
        selfUri: 'https://example.com/doc.arazzo',
    );

    $identity = new SourceIdentity(
        retrievalUri: 'https://cache.example.com/copy.arazzo',
        selfUri: 'https://example.com/doc.arazzo',
        document: $doc,
    );

    $collection = SourceIdentityCollection::empty()->with($identity);

    // Resolves by identity
    expect($collection->findBySelf('https://example.com/doc.arazzo'))->toBe($identity)
        ->and($collection->find('https://example.com/doc.arazzo'))->toBe($identity)
        ->and($collection->hasSelf('https://example.com/doc.arazzo'))->toBeTrue()
        ->and($collection->has('https://example.com/doc.arazzo'))->toBeTrue();

    // Also reachable by retrieval location
    expect($collection->findByRetrieval('https://cache.example.com/copy.arazzo'))->toBe($identity)
        ->and($collection->find('https://cache.example.com/copy.arazzo'))->toBe($identity);
});

it('falls back to location on identity miss (S3)', function (): void {
    $identity = new SourceIdentity(
        retrievalUri: 'https://example.com/local.arazzo',
        selfUri: 'https://example.com/canonical.arazzo',
    );

    $collection = SourceIdentityCollection::empty()->with($identity);

    expect($collection->findBySelf('https://example.com/unknown.arazzo'))->toBeNull()
        ->and($collection->find('https://example.com/unknown.arazzo'))->toBeNull()
        ->and($collection->find('https://example.com/local.arazzo'))->toBe($identity);
});

it('rejects duplicate self with deterministic error naming both retrieval URIs (S7)', function (): void {
    $first = new SourceIdentity(
        retrievalUri: 'https://cache-a.example.com/doc.arazzo',
        selfUri: 'https://example.com/doc.arazzo',
    );

    $second = new SourceIdentity(
        retrievalUri: 'https://cache-b.example.com/doc.arazzo',
        selfUri: 'https://example.com/doc.arazzo',
    );

    $collection = SourceIdentityCollection::empty()->with($first);

    expect(fn () => $collection->with($second))
        ->toThrow(function (SourceResolutionException $e): void {
            expect($e->selfUri)->toBe('https://example.com/doc.arazzo')
                ->and($e->existingRetrievalUri)->toBe('https://cache-a.example.com/doc.arazzo')
                ->and($e->newRetrievalUri)->toBe('https://cache-b.example.com/doc.arazzo')
                ->and($e->getMessage())->toContain('https://cache-a.example.com/doc.arazzo')
                ->and($e->getMessage())->toContain('https://cache-b.example.com/doc.arazzo');
        });
});

it('treats duplicate self with identical retrieval URI as idempotent no-op', function (): void {
    $identity = new SourceIdentity(
        retrievalUri: 'https://cache.example.com/doc.arazzo',
        selfUri: 'https://example.com/doc.arazzo',
    );

    $collection = SourceIdentityCollection::empty()->with($identity);
    $same = $collection->with($identity);

    expect($same)->toBe($collection)
        ->and($same->count())->toBe(1);
});

it('indexes documents without selfUri by retrieval URI only without conflict', function (): void {
    $doc1 = new SourceIdentity(retrievalUri: 'https://example.com/a.json', selfUri: null);
    $doc2 = new SourceIdentity(retrievalUri: 'https://example.com/b.json', selfUri: null);

    $collection = SourceIdentityCollection::empty()
        ->with($doc1)
        ->with($doc2);

    expect($collection->count())->toBe(2)
        ->and($collection->findByRetrieval('https://example.com/a.json'))->toBe($doc1)
        ->and($collection->findByRetrieval('https://example.com/b.json'))->toBe($doc2);
});
