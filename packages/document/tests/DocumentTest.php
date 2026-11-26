<?php

declare(strict_types=1);

namespace Alama\Arazzo\Tests;

use Alama\Arazzo\Contracts\Data\KnownOperations;
use Alama\Arazzo\Contracts\Interfaces\ValidationRuleInterface;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Validation\ErrorCollector;
use Alama\Arazzo\Contracts\Validation\SymbolTable;
use Alama\Arazzo\Document\Document;
use Alama\Arazzo\Document\Parser\DecodePolicy;
use Alama\Arazzo\Document\Parser\Exceptions\LoaderException;

function createDocument(): Document
{
    return Document::create();
}

it('loads and parses from YAML string via loadFromYaml', function (): void {
    $doc = createDocument();
    $yaml = "arazzo: '1.0.0'\ninfo:\n  title: Inline YAML\n  version: '1.0'\nworkflows: []\n";

    $result = $doc->loadFromYaml($yaml, 'custom.yaml');

    expect($result)->toBeInstanceOf(ArazzoDocument::class)
        ->and($result->info->title)->toBe('Inline YAML')
        ->and($result->info->version)->toBe('1.0');
});

it('loads and parses from JSON string via loadFromJson', function (): void {
    $doc = createDocument();
    $json = '{"arazzo":"1.0.0","info":{"title":"Inline JSON","version":"1.0"},"workflows":[]}';

    $result = $doc->loadFromJson($json);

    expect($result)->toBeInstanceOf(ArazzoDocument::class)
        ->and($result->info->title)->toBe('Inline JSON')
        ->and($result->info->version)->toBe('1.0');
});

it('configures decode policy immutably via withDecodePolicy on concrete Document', function (): void {
    $doc = createDocument();
    $strictDoc = $doc->withDecodePolicy(DecodePolicy::default()->withMaxBytes(40));

    expect($strictDoc)->toBeInstanceOf(Document::class)
        ->and($strictDoc)->not->toBe($doc);

    $yaml = "arazzo: '1.0.0'\ninfo:\n  title: Inline YAML that is definitely longer than forty bytes\n  version: '1.0'\nworkflows: []\n";

    expect(fn () => $strictDoc->loadFromYaml($yaml))
        ->toThrow(LoaderException::class, 'maxBytes');

    // Original doc is unaffected
    expect($doc->loadFromYaml($yaml))->toBeInstanceOf(ArazzoDocument::class);
});

it('validates document with known operations', function (): void {
    $doc = createDocument();
    $arazzoDoc = $doc->loadFromYaml("arazzo: '1.0.0'\ninfo:\n  title: Test\n  version: '1.0'\nsourceDescriptions:\n  - name: myApi\n    url: https://example.com/api.json\n    type: openapi\nworkflows:\n  - workflowId: wf1\n    steps:\n      - stepId: step1\n        operationId: myApi.getPet\n");

    // Without knowing operations, remote source is skipped per ADR-005
    $resultNoOps = $doc->validate($arazzoDoc);
    expect($resultNoOps->isValid())->toBeTrue();

    // With known operations that do not contain getPet, it fails validation
    $knownOps = KnownOperations::none()->withSource('myApi', ['otherOp']);
    $resultFailed = $doc->validate($arazzoDoc, $knownOps);
    expect($resultFailed->isValid())->toBeFalse()
        ->and($resultFailed->errors[0]->code)->toBe('step.operationid_resolves');
});

it('validates document with known operations for operationPath', function (): void {
    $doc = createDocument();
    $arazzoDoc = $doc->loadFromYaml("arazzo: '1.0.0'\ninfo:\n  title: Test\n  version: '1.0'\nsourceDescriptions:\n  - name: myApi\n    url: https://example.com/api.json\n    type: openapi\nworkflows:\n  - workflowId: wf1\n    steps:\n      - stepId: step1\n        operationPath: '{\$sourceDescriptions.myApi.url}#/paths/~1pets/get'\n");

    // Without knowing operations, remote source is skipped per ADR-005
    $resultNoOps = $doc->validate($arazzoDoc);
    expect($resultNoOps->isValid())->toBeTrue();

    // With known operations that do not contain /paths/~1pets/get, it fails validation
    $knownOps = KnownOperations::none()->withSource('myApi', ['/paths/~1other/get']);
    $resultFailed = $doc->validate($arazzoDoc, $knownOps);
    expect($resultFailed->isValid())->toBeFalse()
        ->and($resultFailed->errors[0]->code)->toBe('step.operationpath_resolves')
        ->and($resultFailed->errors[0]->path)->toBe('/workflows/0/steps/0/operationPath');

    // With known operations containing the path, it passes validation
    $knownOpsPass = KnownOperations::none()->withSource('myApi', ['/paths/~1pets/get']);
    $resultPass = $doc->validate($arazzoDoc, $knownOpsPass);
    expect($resultPass->isValid())->toBeTrue();
});

it('validates workflow runtime inputs', function (): void {
    $doc = createDocument();
    $yaml = <<<'YAML'
arazzo: '1.0.0'
info:
  title: Test
  version: '1.0'
workflows:
  - workflowId: wf-1
    inputs:
      type: object
      required:
        - userId
      properties:
        userId:
          type: integer
    steps: []
YAML;

    $arazzoDoc = $doc->loadFromYaml($yaml);

    $valid = $doc->validateInputs($arazzoDoc, 'wf-1', ['userId' => 123]);
    expect($valid->isValid())->toBeTrue();

    $invalid = $doc->validateInputs($arazzoDoc, 'wf-1', ['userId' => 'abc']);
    expect($invalid->isValid())->toBeFalse();
});

it('supports immutable per-instance validation rules via withRule()', function (): void {
    $doc = createDocument();
    $arazzoDoc = $doc->loadFromYaml("arazzo: '1.0.0'\ninfo:\n  title: Test\n  version: '1.0'\nsourceDescriptions:\n  - name: api\n    url: https://example.com/api.json\n    type: openapi\nworkflows:\n  - workflowId: wf1\n    steps:\n      - stepId: s1\n        operationId: api.ping\n");

    $customRule = new class() implements ValidationRuleInterface
    {
        public function code(): string
        {
            return 'custom.require_description';
        }

        public function check(ArazzoDocument $doc, SymbolTable $symbols, ErrorCollector $errors): void
        {
            if ($doc->info->description === null) {
                $errors->error($this->code(), 'Info description is required.', '/info/description');
            }
        }
    };

    $withCustom = $doc->withRule($customRule);

    expect($withCustom)->not->toBe($doc)
        ->and($doc->validate($arazzoDoc)->isValid())->toBeTrue()
        ->and($withCustom->validate($arazzoDoc)->isValid())->toBeFalse()
        ->and($withCustom->validate($arazzoDoc)->errors[0]->code)->toBe('custom.require_description');
});
