<?php

declare(strict_types=1);

namespace Alama\Arazzo\Tests\Validator;

use Alama\Arazzo\Document\Parser\Loader;
use Alama\Arazzo\Document\Parser\Parser;
use Alama\Arazzo\Document\Validator\RuleSet;
use Alama\Arazzo\Document\Validator\Validator;

it('validates inputs against workflow declared schema', function (): void {
    $validator = new Validator(RuleSet::default());

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

    $doc = (new Parser())->parse((Loader::new())->loadFromYaml($yaml));

    // Valid inputs
    $validResult = $validator->validateInputs($doc, 'wf-1', ['userId' => 42]);
    expect($validResult->isValid())->toBeTrue()
        ->and($validResult->errors)->toBeEmpty();

    // Invalid inputs
    $invalidResult = $validator->validateInputs($doc, 'wf-1', ['userId' => 'not-an-int']);
    expect($invalidResult->isValid())->toBeFalse()
        ->and($invalidResult->errors)->toHaveCount(1)
        ->and($invalidResult->errors[0]->code)->toBe('preflight.inputs_schema');

    // Unknown workflow
    $unknownResult = $validator->validateInputs($doc, 'unknown-wf', []);
    expect($unknownResult->isValid())->toBeFalse()
        ->and($unknownResult->errors[0]->code)->toBe('preflight.unknown_workflow');
});
