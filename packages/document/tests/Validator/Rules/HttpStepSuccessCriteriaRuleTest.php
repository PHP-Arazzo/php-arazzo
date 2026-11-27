<?php

declare(strict_types=1);

use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Components;
use Alama\Arazzo\Contracts\Spec\Enum\CriterionType;
use Alama\Arazzo\Contracts\Spec\Info;
use Alama\Arazzo\Contracts\Spec\Step;
use Alama\Arazzo\Contracts\Spec\StepFactory;
use Alama\Arazzo\Contracts\Spec\StepFlow;
use Alama\Arazzo\Contracts\Spec\StepIo;
use Alama\Arazzo\Contracts\Spec\StepTarget;
use Alama\Arazzo\Contracts\Spec\SuccessCriterion;
use Alama\Arazzo\Contracts\Spec\Workflow;
use Alama\Arazzo\Contracts\Validation\ErrorCollector;
use Alama\Arazzo\Contracts\Validation\Severity;
use Alama\Arazzo\Contracts\Validation\SymbolTable;
use Alama\Arazzo\Contracts\Validation\ValidatorPolicy;
use Alama\Arazzo\Document\Plugins\ValidationRule\HttpStepSuccessCriteriaRule;
use Alama\Arazzo\Document\Validator\RuleSet;
use Alama\Arazzo\Document\Validator\Validator;

/**
 * @param  list<Step>  $steps
 */
function httpCriteriaRuleDocument(array $steps): ArazzoDocument
{
    return new ArazzoDocument(
        arazzo: '1.1.0',
        info: new Info('t', null, null, '1'),
        sourceDescriptions: [],
        workflows: [new Workflow('w', null, null, null, [], $steps, [], [], [], [])],
        components: new Components([], [], [], []),
        specificationExtensions: [],
    );
}

it('warns when an HTTP operationId step omits successCriteria', function (): void {
    $errors = new ErrorCollector();
    $doc = httpCriteriaRuleDocument([
        StepFactory::http('login', null, new StepFlow(), new StepIo(), operationId: 'loginOp'),
    ]);

    (new HttpStepSuccessCriteriaRule())->check($doc, SymbolTable::build($doc), $errors);

    expect($errors->errors())->toBe([])
        ->and($errors->warnings())->toHaveCount(1)
        ->and($errors->warnings()[0]->code)->toBe('step.http_missing_success_criteria')
        ->and($errors->warnings()[0]->path)->toBe('/workflows/0/steps/0/successCriteria');
});

it('warns when an HTTP operationPath step omits successCriteria', function (): void {
    $errors = new ErrorCollector();
    $doc = httpCriteriaRuleDocument([
        StepFactory::http('ping', null, new StepFlow(), new StepIo(), operationPath: '{$sourceDescriptions.api.url}#/paths/~1ping/get'),
    ]);

    (new HttpStepSuccessCriteriaRule())->check($doc, SymbolTable::build($doc), $errors);

    expect($errors->errors())->toBe([])
        ->and($errors->warnings())->toHaveCount(1)
        ->and($errors->warnings()[0]->code)->toBe('step.http_missing_success_criteria');
});

it('passes without warning when an HTTP step declares successCriteria', function (): void {
    $errors = new ErrorCollector();
    $criterion = new SuccessCriterion(null, '$statusCode == 200', CriterionType::Simple);
    $doc = httpCriteriaRuleDocument([
        StepFactory::http('login', null, new StepFlow(), new StepIo(successCriteria: [$criterion]), operationId: 'loginOp'),
    ]);

    (new HttpStepSuccessCriteriaRule())->check($doc, SymbolTable::build($doc), $errors);

    expect($errors->errors())->toBe([])
        ->and($errors->warnings())->toBe([]);
});

it('ignores sub-workflow and async send or receive steps', function (): void {
    $errors = new ErrorCollector();
    $doc = httpCriteriaRuleDocument([
        StepFactory::workflow('sub', null, new StepFlow(), new StepIo(), 'childFlow'),
        new Step('sendMsg', null, new StepTarget(operationId: 'publishOrder', action: 'send'), new StepFlow(), new StepIo()),
        new Step('waitMsg', null, new StepTarget(operationId: 'onOrderCreated', action: 'receive'), new StepFlow(), new StepIo()),
    ]);

    (new HttpStepSuccessCriteriaRule())->check($doc, SymbolTable::build($doc), $errors);

    expect($errors->errors())->toBe([])
        ->and($errors->warnings())->toBe([]);
});

it('can be promoted to an error via ValidatorPolicy', function (): void {
    $doc = httpCriteriaRuleDocument([
        StepFactory::http('login', null, new StepFlow(), new StepIo(), operationId: 'loginOp'),
    ]);
    $policy = ValidatorPolicy::default()->withSeverity('step.http_missing_success_criteria', Severity::Error);
    $result = (new Validator(new RuleSet([new HttpStepSuccessCriteriaRule()], $policy)))->validate($doc);

    expect($result->isValid())->toBeFalse()
        ->and($result->errors)->toHaveCount(1)
        ->and($result->errors[0]->code)->toBe('step.http_missing_success_criteria');
});
