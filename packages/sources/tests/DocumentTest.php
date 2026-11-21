<?php

declare(strict_types=1);

use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Enum\Format;
use Alama\Arazzo\Contracts\Spec\RawDocument;
use Alama\Arazzo\Document\DocumentInterface;
use Alama\Arazzo\Document\Parser\DecodePolicy;
use Alama\Arazzo\Document\Parser\Exceptions\LoaderException;
use Alama\Arazzo\Document\Validator\Data\ValidationResult;
use Alama\Arazzo\Sources\SourceGraph;

function minimalFixture(): string
{
    return __DIR__.'/fixtures/document/valid.yaml';
}

it('exposes a single entry-point interface', function (): void {
    expect(SourceGraph::document())->toBeInstanceOf(DocumentInterface::class);
});

it('loads and parses a document from a YAML file', function (): void {
    $document = SourceGraph::document();

    expect($document->load(minimalFixture()))->toBeInstanceOf(ArazzoDocument::class);
});

it('parses a raw document', function (): void {
    $document = SourceGraph::document();
    $raw = new RawDocument([
        'arazzo' => '1.0.0',
        'info' => ['title' => 'Inline', 'version' => '1.0'],
        'workflows' => [],
    ], 'inline://minimal.json', Format::Json);

    expect($document->parse($raw))->toBeInstanceOf(ArazzoDocument::class);
});

it('validates a loaded document as valid', function (): void {
    $document = SourceGraph::document();
    $result = $document->validate($document->load(minimalFixture()));

    expect($result)->toBeInstanceOf(ValidationResult::class)
        ->and($result->isValid())->toBeTrue();
});

it('runs preflight validation without side effects on a minimal document', function (): void {
    $document = SourceGraph::document();
    $result = $document->preflight($document->load(minimalFixture()));

    expect($result)->toBeInstanceOf(ValidationResult::class);
});

it('loads and parses from YAML and JSON strings', function (): void {
    $document = SourceGraph::document();
    $yaml = "arazzo: '1.0.0'\ninfo:\n  title: From YAML\n  version: '1.0'\nworkflows: []\n";
    $json = '{"arazzo":"1.0.0","info":{"title":"From JSON","version":"1.0"},"workflows":[]}';

    expect($document->loadFromYaml($yaml)->info->title)->toBe('From YAML')
        ->and($document->loadFromJson($json)->info->title)->toBe('From JSON');
});

it('supports withDecodePolicy on SourceGraph document', function (): void {
    $document = SourceGraph::document()
        ->withDecodePolicy(DecodePolicy::default()->withMaxBytes(25));

    $yaml = "arazzo: '1.0.0'\ninfo:\n  title: Longer than 25 bytes\n  version: '1.0'\nworkflows: []\n";

    expect(fn () => $document->loadFromYaml($yaml))
        ->toThrow(LoaderException::class, 'maxBytes');
});
