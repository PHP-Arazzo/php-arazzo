<?php

declare(strict_types=1);

namespace Alama\Arazzo\Tests\Validation\Rules;

use Alama\Arazzo\Contracts\Spec\Action\FailureGotoAction;
use Alama\Arazzo\Contracts\Spec\Action\SuccessGotoAction;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Components;
use Alama\Arazzo\Contracts\Spec\Enum\ParameterIn;
use Alama\Arazzo\Contracts\Spec\Enum\SpecVersion;
use Alama\Arazzo\Contracts\Spec\Info;
use Alama\Arazzo\Contracts\Spec\Parameter;
use Alama\Arazzo\Contracts\Spec\Reusable;
use Alama\Arazzo\Contracts\Spec\Workflow;
use Alama\Arazzo\Contracts\Validation\ErrorCollector;
use Alama\Arazzo\Contracts\Validation\SymbolTable;
use Alama\Arazzo\Document\Validator\Rules\ParameterReusableRefResolvesRule;
use Alama\Arazzo\Tests\Support\Fx;

it('rejects missing workflow parameter reference', function (): void {
    $errors = new ErrorCollector();
    $doc = Fx::doc(
        specVersion: SpecVersion::V1_1,
        workflows: [
            Fx::wf('w', [], parameters: [
                new Reusable('$components.parameters.missing'),
            ]),
        ],
        components: new Components([], [], [], []),
    );
    (new ParameterReusableRefResolvesRule())->check($doc, SymbolTable::build($doc), $errors);
    expect($errors->errors())->toHaveCount(1)
        ->and($errors->errors()[0]->code)->toBe('parameter.reusable_ref_resolves')
        ->and($errors->errors()[0]->path)->toBe('/workflows/0/parameters/0/reference');
});

it('rejects missing step parameter reference', function (): void {
    $errors = new ErrorCollector();
    $doc = Fx::doc(
        specVersion: SpecVersion::V1_1,
        workflows: [
            Fx::wf('w', [
                Fx::step('s', 'op', params: [
                    new Reusable('$components.parameters.missing'),
                ]),
            ]),
        ],
        components: new Components([], [], [], []),
    );
    (new ParameterReusableRefResolvesRule())->check($doc, SymbolTable::build($doc), $errors);
    expect($errors->errors())->toHaveCount(1)
        ->and($errors->errors()[0]->code)->toBe('parameter.reusable_ref_resolves')
        ->and($errors->errors()[0]->path)->toBe('/workflows/0/steps/0/parameters/0/reference');
});

it('rejects missing step action parameter reference', function (): void {
    $errors = new ErrorCollector();
    $action = new SuccessGotoAction('goto', null, 'target', [], [
        new Reusable('$components.parameters.missing'),
    ]);
    $doc = Fx::doc(
        specVersion: SpecVersion::V1_1,
        workflows: [
            Fx::wf('w', [
                Fx::step('s', 'op', onSuccess: [$action]),
            ]),
        ],
        components: new Components([], [], [], []),
    );
    (new ParameterReusableRefResolvesRule())->check($doc, SymbolTable::build($doc), $errors);
    expect($errors->errors())->toHaveCount(1)
        ->and($errors->errors()[0]->code)->toBe('parameter.reusable_ref_resolves')
        ->and($errors->errors()[0]->path)->toBe('/workflows/0/steps/0/onSuccess/0/parameters/0/reference');
});

it('rejects missing workflow action parameter reference', function (): void {
    $errors = new ErrorCollector();
    $action = new FailureGotoAction('goto', null, 'target', [], [
        new Reusable('$components.parameters.missing'),
    ]);
    $doc = new ArazzoDocument(
        arazzo: '1.1.0',
        info: new Info('T', null, null, '1'),
        sourceDescriptions: [],
        workflows: [
            new Workflow(
                workflowId: 'wf',
                summary: null,
                description: null,
                inputs: null,
                dependsOn: [],
                steps: [],
                successActions: [$action],
                failureActions: [],
                outputs: [],
                parameters: [],
            ),
        ],
        components: new Components([], [], [], []),
        specificationExtensions: [],
        specVersion: SpecVersion::V1_1,
    );
    (new ParameterReusableRefResolvesRule())->check($doc, SymbolTable::build($doc), $errors);
    expect($errors->errors())->toHaveCount(1)
        ->and($errors->errors()[0]->code)->toBe('parameter.reusable_ref_resolves')
        ->and($errors->errors()[0]->path)->toBe('/workflows/0/successActions/0/parameters/0/reference');
});

it('rejects wrong component namespace', function (): void {
    $errors = new ErrorCollector();
    $doc = Fx::doc(
        specVersion: SpecVersion::V1_1,
        workflows: [
            Fx::wf('w', [], parameters: [
                new Reusable('$components.inputs.x'),
            ]),
        ],
        components: new Components([], [], [], []),
    );
    (new ParameterReusableRefResolvesRule())->check($doc, SymbolTable::build($doc), $errors);
    expect($errors->errors())->toHaveCount(1)
        ->and($errors->errors()[0]->code)->toBe('parameter.reusable_ref_resolves')
        ->and($errors->errors()[0]->path)->toBe('/workflows/0/parameters/0/reference');
});

it('accepts resolving reference', function (): void {
    $errors = new ErrorCollector();
    $doc = Fx::doc(
        specVersion: SpecVersion::V1_1,
        workflows: [
            Fx::wf('w', [], parameters: [
                new Reusable('$components.parameters.x'),
            ]),
        ],
        components: new Components([], ['x' => new Parameter('x', ParameterIn::Query, 'value')], [], []),
    );
    (new ParameterReusableRefResolvesRule())->check($doc, SymbolTable::build($doc), $errors);
    expect($errors->errors())->toHaveCount(0);
});
