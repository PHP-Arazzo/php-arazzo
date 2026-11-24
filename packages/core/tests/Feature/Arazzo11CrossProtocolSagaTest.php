<?php

declare(strict_types=1);

use Alama\Arazzo\Contracts\Spec\Enum\Format;
use Alama\Arazzo\Contracts\Spec\Enum\SpecVersion;
use Alama\Arazzo\Contracts\Spec\RawDocument;
use Alama\Arazzo\Document\Parser\Parser;
use Alama\Arazzo\Document\Plugins\ValidationRule\AsyncApiFieldsRequire11Rule;
use Alama\Arazzo\Document\Plugins\ValidationRule\DocumentArazzoVersionRule;
use Alama\Arazzo\Document\Plugins\ValidationRule\ParameterQuerystringOperationShapeRule;
use Alama\Arazzo\Document\Plugins\ValidationRule\SelfUriSyntaxRule;
use Alama\Arazzo\Document\Plugins\ValidationRule\StepParameterInValidRule;
use Alama\Arazzo\Document\Plugins\ValidationRule\SubWorkflowInvokeTargetResolvesRule;
use Alama\Arazzo\Document\Validator\RuleSet;
use Alama\Arazzo\Document\Validator\Validator;
use Alama\Arazzo\Expression\Plugins\ValidationRule\SelectorTypeSupportedRule;
use Symfony\Component\Yaml\Yaml;

function loadFixture(string $filename): RawDocument
{
    $path = __DIR__.'/../fixtures/parser/'.$filename;

    return new RawDocument(Yaml::parseFile($path), $path, Format::Yaml);
}

it('parses the comprehensive 1.1.0 cross-protocol saga fixture', function () {
    $doc = (new Parser())->parse(loadFixture('arazzo-1.1-cross-protocol-saga.yaml'));

    expect($doc->specVersion)->toBe(SpecVersion::V1_1)
        ->and($doc->self)->toBe('https://example.com/rides/spec.arazzo.yaml')
        ->and($doc->workflows)->toHaveCount(2);
});

it('validates the comprehensive fixture cleanly with the 1.1 ruleset', function () {
    $doc = (new Parser())->parse(loadFixture('arazzo-1.1-cross-protocol-saga.yaml'));

    $ruleset = new RuleSet([
        new DocumentArazzoVersionRule(),
        new StepParameterInValidRule(),
        new SelectorTypeSupportedRule(),
        new SubWorkflowInvokeTargetResolvesRule(),
        new SelfUriSyntaxRule(),
        new AsyncApiFieldsRequire11Rule(),
        new ParameterQuerystringOperationShapeRule(),
    ]);

    $result = (new Validator($ruleset))->validate($doc);
    expect($result->errors)->toBe([]);
});

it('preserves 1.0.0 parsing', function () {
    $doc = (new Parser())->parse(loadFixture('arazzo-1.0-still-parses.yaml'));
    expect($doc->specVersion)->toBe(SpecVersion::V1_0)
        ->and($doc->self)->toBeNull()
        ->and($doc->firstWorkflow()->steps[0]->io->outputs)->toHaveKey('id');
});

it('parses per-feature narrow fixtures', function (string $filename) {
    $doc = (new Parser())->parse(loadFixture($filename));
    expect($doc->specVersion)->toBe(SpecVersion::V1_1);
})->with([
    'arazzo-1.1-selector-only.yaml',
    'arazzo-1.1-invoke-only.yaml',
    'arazzo-1.1-querystring-only.yaml',
]);
