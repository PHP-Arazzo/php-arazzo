<?php

declare(strict_types=1);

namespace Alama\Arazzo\Tests\Validation\Rules;

use Alama\Arazzo\Contracts\Data\SourceResolution;
use Alama\Arazzo\Contracts\Data\SourceResolutionCollection;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Components;
use Alama\Arazzo\Contracts\Spec\Enum\SourceType;
use Alama\Arazzo\Contracts\Spec\Info;
use Alama\Arazzo\Contracts\Spec\SourceDescription;
use Alama\Arazzo\Contracts\Validation\ErrorCollector;
use Alama\Arazzo\Contracts\Validation\SymbolTable;
use Alama\Arazzo\Document\Document;
use Alama\Arazzo\Document\Plugins\ValidationRule\SourceTypeInferenceFailedRule;
use Alama\Arazzo\Document\Plugins\ValidationRule\SourceTypeMatchesRule;
use Alama\Arazzo\Document\Plugins\ValidationRule\SourceUnparseableRule;
use Alama\Arazzo\Document\Plugins\ValidationRule\SourceUnreadableRule;

function createDocWithSources(array $sources): ArazzoDocument
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

it('no-ops across all source integrity rules when resolve mode is not active', function (): void {
    $sources = [
        new SourceDescription('api', './missing.yaml', SourceType::Openapi),
    ];
    $doc = createDocWithSources($sources);
    $symbols = SymbolTable::build($doc); // isResolved() is false by default
    $collector = new ErrorCollector();

    (new SourceUnreadableRule())->check($doc, $symbols, $collector);
    (new SourceUnparseableRule())->check($doc, $symbols, $collector);
    (new SourceTypeMatchesRule())->check($doc, $symbols, $collector);
    (new SourceTypeInferenceFailedRule())->check($doc, $symbols, $collector);

    expect($collector->errors())->toBe([]);
});

it('SourceUnreadableRule reports source.unreadable error at url pointer', function (): void {
    $sources = [
        new SourceDescription('ok_source', './openapi.yaml', SourceType::Openapi),
        new SourceDescription('missing', './missing.yaml', SourceType::Openapi),
    ];
    $doc = createDocWithSources($sources);

    $resolutions = SourceResolutionCollection::fromArray([
        SourceResolution::valid('ok_source', './openapi.yaml', SourceType::Openapi),
        SourceResolution::unreadable('missing', './missing.yaml', 'File not found'),
    ], isResolved: true);

    $symbols = SymbolTable::build($doc, sourceResolutions: $resolutions);
    $collector = new ErrorCollector();

    $rule = new SourceUnreadableRule();
    expect($rule->code())->toBe('source.unreadable');

    $rule->check($doc, $symbols, $collector);

    expect($collector->errors())->toHaveCount(1)
        ->and($collector->errors()[0]->code)->toBe('source.unreadable')
        ->and($collector->errors()[0]->path)->toBe('/sourceDescriptions/1/url')
        ->and($collector->errors()[0]->message)->toBe("Source 'missing' at './missing.yaml' is unreadable: File not found");
});

it('SourceUnparseableRule reports source.unparseable error at url pointer', function (): void {
    $sources = [
        new SourceDescription('bad', './bad.yaml', SourceType::Openapi),
    ];
    $doc = createDocWithSources($sources);

    $resolutions = SourceResolutionCollection::fromArray([
        SourceResolution::unparseable('bad', './bad.yaml', 'Invalid YAML syntax'),
    ], isResolved: true);

    $symbols = SymbolTable::build($doc, sourceResolutions: $resolutions);
    $collector = new ErrorCollector();

    $rule = new SourceUnparseableRule();
    expect($rule->code())->toBe('source.unparseable');

    $rule->check($doc, $symbols, $collector);

    expect($collector->errors())->toHaveCount(1)
        ->and($collector->errors()[0]->code)->toBe('source.unparseable')
        ->and($collector->errors()[0]->path)->toBe('/sourceDescriptions/0/url')
        ->and($collector->errors()[0]->message)->toBe("Source 'bad' at './bad.yaml' could not be parsed: Invalid YAML syntax");
});

it('SourceTypeMatchesRule reports source.type_matches error at type pointer on mismatch', function (): void {
    $sources = [
        new SourceDescription('api', './openapi.yaml', SourceType::Arazzo),
    ];
    $doc = createDocWithSources($sources);

    $resolutions = SourceResolutionCollection::fromArray([
        SourceResolution::typeMismatch('api', './openapi.yaml', SourceType::Arazzo, SourceType::Openapi),
    ], isResolved: true);

    $symbols = SymbolTable::build($doc, sourceResolutions: $resolutions);
    $collector = new ErrorCollector();

    $rule = new SourceTypeMatchesRule();
    expect($rule->code())->toBe('source.type_matches');

    $rule->check($doc, $symbols, $collector);

    expect($collector->errors())->toHaveCount(1)
        ->and($collector->errors()[0]->code)->toBe('source.type_matches')
        ->and($collector->errors()[0]->path)->toBe('/sourceDescriptions/0/type')
        ->and($collector->errors()[0]->message)->toBe("Source 'api' declares type 'arazzo', but content matches 'openapi'");
});

it('SourceTypeInferenceFailedRule reports source.type_inference_failed at url pointer', function (): void {
    $sources = [
        new SourceDescription('untyped', './unknown.yaml', null),
    ];
    $doc = createDocWithSources($sources);

    $resolutions = SourceResolutionCollection::fromArray([
        SourceResolution::typeInferenceFailed('untyped', './unknown.yaml', 'unrecognized format'),
    ], isResolved: true);

    $symbols = SymbolTable::build($doc, sourceResolutions: $resolutions);
    $collector = new ErrorCollector();

    $rule = new SourceTypeInferenceFailedRule();
    expect($rule->code())->toBe('source.type_inference_failed');

    $rule->check($doc, $symbols, $collector);

    expect($collector->errors())->toHaveCount(1)
        ->and($collector->errors()[0]->code)->toBe('source.type_inference_failed')
        ->and($collector->errors()[0]->path)->toBe('/sourceDescriptions/0/url')
        ->and($collector->errors()[0]->message)->toBe("Failed to infer source type for 'untyped' at './unknown.yaml': unrecognized document format");
});

it('validates document with source resolutions through Document facade', function (): void {
    $yaml = <<<'YAML'
arazzo: 1.0.0
info:
  title: Test Doc
  version: 1.0.0
sourceDescriptions:
  - name: missing
    url: ./missing.yaml
    type: openapi
workflows:
  - workflowId: testWf
    steps:
      - stepId: step1
        operationId: missing.doSomething
YAML;

    $doc = Document::create()->loadFromYaml($yaml);

    $resolutions = SourceResolutionCollection::fromArray([
        SourceResolution::unreadable('missing', './missing.yaml', 'File not found'),
    ], isResolved: true);

    $result = Document::create()->validate($doc, sourceResolutions: $resolutions);

    expect($result->isValid())->toBeFalse()
        ->and($result->errors)->toHaveCount(1)
        ->and($result->errors[0]->code)->toBe('source.unreadable')
        ->and($result->errors[0]->path)->toBe('/sourceDescriptions/0/url');
});
