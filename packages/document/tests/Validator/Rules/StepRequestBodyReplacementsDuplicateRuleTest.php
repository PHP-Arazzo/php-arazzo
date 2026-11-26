<?php

declare(strict_types=1);

namespace Alama\Arazzo\Tests\Validation\Rules;

use Alama\Arazzo\Contracts\Spec\PayloadReplacement;
use Alama\Arazzo\Contracts\Spec\RequestBody;
use Alama\Arazzo\Contracts\Validation\ErrorCollector;
use Alama\Arazzo\Contracts\Validation\SymbolTable;
use Alama\Arazzo\Document\Plugins\ValidationRule\StepRequestBodyReplacementsDuplicateRule;
use Alama\Arazzo\Document\Validator\RuleSet;
use Alama\Arazzo\Document\Validator\Validator;
use Alama\Arazzo\Tests\Support\Fx;

it('S1: rejects duplicate replacement targets in the same requestBody', function (): void {
    $doc = Fx::doc(workflows: [
        Fx::wf('wf', [
            Fx::step('one', 'op', body: new RequestBody('application/json', null, [
                new PayloadReplacement('/a', '2'),
                new PayloadReplacement('/a', '3'),
            ])),
        ]),
    ]);

    $errors = new ErrorCollector();
    (new StepRequestBodyReplacementsDuplicateRule())->check($doc, SymbolTable::build($doc), $errors);

    expect($errors->errors())->toHaveCount(1)
        ->and($errors->errors()[0]->code)->toBe('step.request_body_replacements_duplicate_target')
        ->and($errors->errors()[0]->path)->toBe('/workflows/0/steps/0/requestBody/replacements/1/target')
        ->and($errors->errors()[0]->message)->toBe("Duplicate replacement target '/a' in requestBody of step 'one' at index 1 (previously defined at index 0).");
});

it('S2: flags duplicate target regardless of selector type', function (): void {
    $doc = Fx::doc(workflows: [
        Fx::wf('wf', [
            Fx::step('one', 'op', body: new RequestBody('application/json', null, [
                new PayloadReplacement('/a', '2', 'jsonpointer'),
                new PayloadReplacement('/a', '3', 'jsonpath'),
            ])),
        ]),
    ]);

    $errors = new ErrorCollector();
    (new StepRequestBodyReplacementsDuplicateRule())->check($doc, SymbolTable::build($doc), $errors);

    expect($errors->errors())->toHaveCount(1)
        ->and($errors->errors()[0]->code)->toBe('step.request_body_replacements_duplicate_target')
        ->and($errors->errors()[0]->path)->toBe('/workflows/0/steps/0/requestBody/replacements/1/target')
        ->and($errors->errors()[0]->message)->toContain("Duplicate replacement target '/a'");
});

it('S3: allows distinct replacement targets', function (): void {
    $doc = Fx::doc(workflows: [
        Fx::wf('wf', [
            Fx::step('one', 'op', body: new RequestBody('application/json', null, [
                new PayloadReplacement('/a', '2'),
                new PayloadReplacement('/b', '3'),
            ])),
        ]),
    ]);

    $errors = new ErrorCollector();
    (new StepRequestBodyReplacementsDuplicateRule())->check($doc, SymbolTable::build($doc), $errors);

    expect($errors->errors())->toBeEmpty();
});

it('S4: reports duplicate target pointer for each subsequent duplicate', function (): void {
    $doc = Fx::doc(workflows: [
        Fx::wf('wf', [
            Fx::step('one', 'op', body: new RequestBody('application/json', null, [
                new PayloadReplacement('/a', '1'),
                new PayloadReplacement('/a', '2'),
                new PayloadReplacement('/a', '3'),
            ])),
        ]),
    ]);

    $errors = new ErrorCollector();
    (new StepRequestBodyReplacementsDuplicateRule())->check($doc, SymbolTable::build($doc), $errors);

    expect($errors->errors())->toHaveCount(2)
        ->and($errors->errors()[0]->path)->toBe('/workflows/0/steps/0/requestBody/replacements/1/target')
        ->and($errors->errors()[0]->message)->toBe("Duplicate replacement target '/a' in requestBody of step 'one' at index 1 (previously defined at index 0).")
        ->and($errors->errors()[1]->path)->toBe('/workflows/0/steps/0/requestBody/replacements/2/target')
        ->and($errors->errors()[1]->message)->toBe("Duplicate replacement target '/a' in requestBody of step 'one' at index 2 (previously defined at index 0).");
});

it('handles steps without requestBody or with empty replacements', function (): void {
    $doc = Fx::doc(workflows: [
        Fx::wf('wf', [
            Fx::step('one', 'op', body: null),
            Fx::step('two', 'op', body: new RequestBody('application/json', null, [])),
        ]),
    ]);

    $errors = new ErrorCollector();
    (new StepRequestBodyReplacementsDuplicateRule())->check($doc, SymbolTable::build($doc), $errors);

    expect($errors->errors())->toBeEmpty();
});

it('flags duplicate empty string targets uniformly', function (): void {
    $doc = Fx::doc(workflows: [
        Fx::wf('wf', [
            Fx::step('one', 'op', body: new RequestBody('application/json', null, [
                new PayloadReplacement('', '1'),
                new PayloadReplacement('', '2'),
            ])),
        ]),
    ]);

    $errors = new ErrorCollector();
    (new StepRequestBodyReplacementsDuplicateRule())->check($doc, SymbolTable::build($doc), $errors);

    expect($errors->errors())->toHaveCount(1)
        ->and($errors->errors()[0]->path)->toBe('/workflows/0/steps/0/requestBody/replacements/1/target')
        ->and($errors->errors()[0]->message)->toBe("Duplicate replacement target '' in requestBody of step 'one' at index 1 (previously defined at index 0).");
});

it('is registered in RuleSet::default() and fails validation for duplicate replacement targets', function (): void {
    $doc = Fx::doc(workflows: [
        Fx::wf('wf', [
            Fx::step('one', 'op', body: new RequestBody('application/json', null, [
                new PayloadReplacement('/a', '2'),
                new PayloadReplacement('/a', '3'),
            ])),
        ]),
    ]);

    $result = (new Validator(RuleSet::default()))->validate($doc);

    expect($result->isValid())->toBeFalse()
        ->and(array_map(fn ($e) => $e->code, $result->errors))->toContain('step.request_body_replacements_duplicate_target');
});
