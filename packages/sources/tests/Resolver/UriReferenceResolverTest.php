<?php

declare(strict_types=1);

namespace Alama\Arazzo\Tests\Resolver;

use Alama\Arazzo\Sources\Resolver\UriReferenceResolver;

it('preserves already-absolute URLs with scheme', function (): void {
    expect(UriReferenceResolver::resolve('https://example.com/api.json', 'https://other.example.com/base/'))
        ->toBe('https://example.com/api.json')
        ->and(UriReferenceResolver::resolve('file:///var/specs/api.yaml', '/tmp'))
        ->toBe('file:///var/specs/api.yaml');
});

it('resolves relative URL against an absolute HTTP base URI (S4)', function (): void {
    $resolved = UriReferenceResolver::resolve('./other.arazzo', 'https://example.com/base/doc.arazzo');
    expect($resolved)->toBe('https://example.com/base/other.arazzo');

    $withParent = UriReferenceResolver::resolve('../sibling/api.json', 'https://example.com/sub/dir/doc.arazzo');
    expect($withParent)->toBe('https://example.com/sub/sibling/api.json');
});

it('resolves relative self against retrieval URI (S5)', function (): void {
    $resolved = UriReferenceResolver::resolve('./doc.arazzo', 'https://example.com/base/');
    expect($resolved)->toBe('https://example.com/base/doc.arazzo');

    $fileSelf = UriReferenceResolver::resolve('./doc.arazzo', 'file:///var/specs/main.arazzo.yaml');
    expect($fileSelf)->toBe('file:///var/specs/doc.arazzo');
});

it('resolves relative filesystem paths to canonical file URIs', function (): void {
    expect(UriReferenceResolver::resolve('./local/api.json', '/base'))
        ->toBe('file:///base/local/api.json')
        ->and(UriReferenceResolver::resolve('../sibling/api.json', '/path/to/base'))
        ->toBe('file:///path/to/sibling/api.json');
});
