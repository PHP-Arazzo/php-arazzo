<?php

declare(strict_types=1);

namespace Alama\Arazzo\Tests\Validation\Rules;

use Alama\Arazzo\Contracts\Spec\Action\SubWorkflowSuccessAction;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Components;
use Alama\Arazzo\Contracts\Spec\Info;
use Alama\Arazzo\Contracts\Spec\StepFactory;
use Alama\Arazzo\Contracts\Spec\StepFlow;
use Alama\Arazzo\Contracts\Spec\StepIo;
use Alama\Arazzo\Contracts\Spec\Workflow;
use Alama\Arazzo\Contracts\Validation\ErrorCollector;
use Alama\Arazzo\Contracts\Validation\SymbolTable;
use Alama\Arazzo\Document\Plugins\ValidationRule\ActionTypeValidRule;

it('is a no-op with reserved code', function (): void {
    $doc = new ArazzoDocument('1.0.0', new Info('T', null, null, '1'), [], [], new Components([], [], [], []), []);
    $ec = new ErrorCollector();
    $r = new ActionTypeValidRule();
    $r->check($doc, SymbolTable::build($doc), $ec);
    expect($ec->errors())->toBe([])->and($r->code())->toBe('action.type_valid');
});

it('accepts invoke actions in permissive mode', function (): void {
    $sub = new SubWorkflowSuccessAction('sub', 'w2', [], []);
    $step = StepFactory::http(
        's', null,
        new StepFlow(onSuccess: [$sub]),
        new StepIo(),
        operationId: 'op',
    );
    $doc = new ArazzoDocument(
        '1.1.0', new Info('T', null, null, '1'),
        [],
        [new Workflow('w', null, null, null, [], [$step], [], [], [], [])],
        new Components([], [], [], []),
        [],
    );
    $ec = new ErrorCollector();
    (new ActionTypeValidRule(conformance: false))->check($doc, SymbolTable::build($doc), $ec);
    expect($ec->errors())->toBe([]);
});

it('rejects invoke actions in conformance mode', function (): void {
    $sub = new SubWorkflowSuccessAction('sub', 'w2', [], [], null, 'x-invoke');
    $step = StepFactory::http(
        's', null,
        new StepFlow(onSuccess: [$sub]),
        new StepIo(),
        operationId: 'op',
    );
    $doc = new ArazzoDocument(
        '1.1.0', new Info('T', null, null, '1'),
        [],
        [new Workflow('w', null, null, null, [], [$step], [], [], [], [])],
        new Components([], [], [], []),
        [],
    );
    $ec = new ErrorCollector();
    (new ActionTypeValidRule(conformance: true))->check($doc, SymbolTable::build($doc), $ec);
    expect($ec->errors())->toHaveCount(1)
        ->and($ec->errors()[0]->code)->toBe('action.type_valid')
        ->and($ec->errors()[0]->message)->toContain('non-spec extension');
});
