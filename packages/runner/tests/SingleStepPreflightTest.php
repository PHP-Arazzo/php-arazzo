<?php

declare(strict_types=1);

use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Components;
use Alama\Arazzo\Contracts\Spec\Enum\ParameterIn;
use Alama\Arazzo\Contracts\Spec\Expression;
use Alama\Arazzo\Contracts\Spec\Info;
use Alama\Arazzo\Contracts\Spec\Parameter;
use Alama\Arazzo\Contracts\Spec\StepFactory;
use Alama\Arazzo\Contracts\Spec\StepFlow;
use Alama\Arazzo\Contracts\Spec\StepIo;
use Alama\Arazzo\Contracts\Spec\Workflow;
use Alama\Arazzo\Runner\Policy\ExecutionPolicy;
use Alama\Arazzo\Runner\Runner;

test('ExecutionPolicy configures withNoDeps immutably', function (): void {
    $policy = ExecutionPolicy::default();
    expect($policy->noDeps)->toBeFalse();

    $noDepsPolicy = $policy->withNoDeps(true);
    expect($noDepsPolicy)->not->toBe($policy)
        ->and($noDepsPolicy->noDeps)->toBeTrue()
        ->and($policy->noDeps)->toBeFalse();

    $restored = $noDepsPolicy->withNoDeps(false);
    expect($restored->noDeps)->toBeFalse();
});

test('preflight validates step existence and reports error with valid step IDs (S4)', function (): void {
    $doc = new ArazzoDocument(
        arazzo: '1.0.1',
        info: new Info('test', null, null, '1.0'),
        sourceDescriptions: [],
        workflows: [
            new Workflow(
                workflowId: 'order-wf',
                summary: null,
                description: null,
                inputs: null,
                dependsOn: [],
                steps: [
                    StepFactory::http('login', null, new StepFlow(), new StepIo(), 'loginOp'),
                    StepFactory::http('fetch-config', null, new StepFlow(), new StepIo(), 'configOp'),
                    StepFactory::http('create-order', null, new StepFlow(), new StepIo(), 'orderOp'),
                ],
                successActions: [],
                failureActions: [],
                outputs: [],
                parameters: [],
            ),
        ],
        components: new Components([], [], [], []),
        specificationExtensions: [],
    );

    $runner = Runner::create();

    // Valid step in workflow
    $validResult = $runner->preflight($doc, 'order-wf', [], 'create-order');
    expect($validResult->isValid())->toBeTrue();

    // Valid step with implicit workflow
    $implicitValidResult = $runner->preflight($doc, null, [], 'create-order');
    expect($implicitValidResult->isValid())->toBeTrue();

    // Unknown step in workflow (R4 / S4)
    $unknownResult = $runner->preflight($doc, 'order-wf', [], 'nonexistent');
    expect($unknownResult->isValid())->toBeFalse()
        ->and($unknownResult->errors)->toHaveCount(1)
        ->and($unknownResult->errors[0]->message)->toContain('login', 'fetch-config', 'create-order');

    // Unknown step with implicit workflow
    $unknownImplicit = $runner->preflight($doc, null, [], 'nonexistent');
    expect($unknownImplicit->isValid())->toBeFalse()
        ->and($unknownImplicit->errors[0]->message)->toContain('login', 'fetch-config', 'create-order');
});

test('preflight flags ambiguous step when stepId exists in multiple workflows', function (): void {
    $doc = new ArazzoDocument(
        arazzo: '1.0.1',
        info: new Info('test', null, null, '1.0'),
        sourceDescriptions: [],
        workflows: [
            new Workflow('wf1', null, null, null, [], [
                StepFactory::http('login', null, new StepFlow(), new StepIo(), 'loginOp'),
            ], [], [], [], []),
            new Workflow('wf2', null, null, null, [], [
                StepFactory::http('login', null, new StepFlow(), new StepIo(), 'loginOp2'),
            ], [], [], [], []),
        ],
        components: new Components([], [], [], []),
        specificationExtensions: [],
    );

    $runner = Runner::create();

    // Ambiguous without workflowId
    $ambiguousResult = $runner->preflight($doc, null, [], 'login');
    expect($ambiguousResult->isValid())->toBeFalse()
        ->and($ambiguousResult->errors[0]->message)->toContain('wf1', 'wf2');

    // Disambiguated with workflowId
    $disambiguated = $runner->preflight($doc, 'wf1', [], 'login');
    expect($disambiguated->isValid())->toBeTrue();
});

test('preflight detects missing dependency output in noDeps mode', function (): void {
    $doc = new ArazzoDocument(
        arazzo: '1.0.1',
        info: new Info('test', null, null, '1.0'),
        sourceDescriptions: [],
        workflows: [
            new Workflow('wf1', null, null, null, [], [
                StepFactory::http('login', null, new StepFlow(), new StepIo(), 'loginOp'),
                StepFactory::http(
                    'step2',
                    null,
                    new StepFlow(),
                    new StepIo(parameters: [
                        new Parameter('tok', ParameterIn::Header, new Expression('{$steps.login.outputs.token}')),
                    ]),
                    'step2Op',
                ),
            ], [], [], [], []),
        ],
        components: new Components([], [], [], []),
        specificationExtensions: [],
    );

    $noDepsRunner = Runner::create()->withPolicy(ExecutionPolicy::default()->withNoDeps(true));

    // Missing output in inputs
    $resMissing = $noDepsRunner->preflight($doc, 'wf1', [], 'step2');
    expect($resMissing->isValid())->toBeFalse()
        ->and($resMissing->errors)->toHaveCount(1)
        ->and($resMissing->errors[0]->code)->toBe('step.missing_dependency_output');

    // Provided in inputs
    $resProvided = $noDepsRunner->preflight($doc, 'wf1', ['token' => 'secret'], 'step2');
    expect($resProvided->isValid())->toBeTrue();
});
