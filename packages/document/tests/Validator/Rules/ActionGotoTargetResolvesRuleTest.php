<?php

declare(strict_types=1);

namespace Alama\Arazzo\Tests\Validation\Rules;

use Alama\Arazzo\Contracts\Spec\Action\FailureEndAction;
use Alama\Arazzo\Contracts\Spec\Action\FailureGotoAction;
use Alama\Arazzo\Contracts\Spec\Action\RetryAction;
use Alama\Arazzo\Contracts\Spec\Action\SuccessEndAction;
use Alama\Arazzo\Contracts\Spec\Action\SuccessGotoAction;
use Alama\Arazzo\Contracts\Spec\Workflow;
use Alama\Arazzo\Contracts\Validation\ErrorCollector;
use Alama\Arazzo\Contracts\Validation\SymbolTable;
use Alama\Arazzo\Document\Plugins\ValidationRule\ActionGotoTargetResolvesRule;
use Alama\Arazzo\Tests\Support\Fx;

it('flags unknown workflowId and skips non-goto/retry actions', function (): void {
    $end = new SuccessEndAction('e', []);
    $goto = new SuccessGotoAction('g', null, 'ghost-wf', []);
    $s = Fx::step('s', 'op', onSuccess: [$end, $goto]);
    $doc = Fx::doc(workflows: [Fx::wf('w', [$s])]);
    $ec = new ErrorCollector();
    (new ActionGotoTargetResolvesRule())->check($doc, SymbolTable::build($doc), $ec);
    expect($ec->errors())->toHaveCount(1);
});

it('flags failure goto and retry with unknown targets', function (): void {
    $f = new FailureGotoAction('g', 'ghost', null, []);
    $r = new RetryAction('r', 1, 1, 'ghost2', null, []);
    $s = Fx::step('s', 'op', onFailure: [$f, $r]);
    $doc = Fx::doc(workflows: [Fx::wf('w', [$s])]);
    $ec = new ErrorCollector();
    (new ActionGotoTargetResolvesRule())->check($doc, SymbolTable::build($doc), $ec);
    expect($ec->errors())->toHaveCount(2);
});

it('flags workflow-level success goto with unknown stepId', function (): void {
    $goto = new SuccessGotoAction('go', 'nonexistent', null, []);
    $s = Fx::step('one', 'op');
    $wf = new Workflow(
        workflowId: 'wf',
        summary: null,
        description: null,
        inputs: null,
        dependsOn: [],
        steps: [$s],
        successActions: [$goto],
        failureActions: [],
        outputs: [],
        parameters: [],
    );
    $doc = Fx::doc(workflows: [$wf]);
    $ec = new ErrorCollector();

    (new ActionGotoTargetResolvesRule())->check($doc, SymbolTable::build($doc), $ec);

    expect($ec->errors())->toHaveCount(1)
        ->and($ec->errors()[0]->code)->toBe('action.goto_target_resolves')
        ->and($ec->errors()[0]->path)->toBe('/workflows/0/successActions/0/stepId')
        ->and($ec->errors()[0]->message)->toBe("Action references unknown stepId 'nonexistent'.");
});

it('flags workflow-level failure goto with unknown stepId', function (): void {
    $goto = new FailureGotoAction('recover', 'missing', null, []);
    $s = Fx::step('one', 'op');
    $wf = new Workflow(
        workflowId: 'wf',
        summary: null,
        description: null,
        inputs: null,
        dependsOn: [],
        steps: [$s],
        successActions: [],
        failureActions: [$goto],
        outputs: [],
        parameters: [],
    );
    $doc = Fx::doc(workflows: [$wf]);
    $ec = new ErrorCollector();

    (new ActionGotoTargetResolvesRule())->check($doc, SymbolTable::build($doc), $ec);

    expect($ec->errors())->toHaveCount(1)
        ->and($ec->errors()[0]->code)->toBe('action.goto_target_resolves')
        ->and($ec->errors()[0]->path)->toBe('/workflows/0/failureActions/0/stepId')
        ->and($ec->errors()[0]->message)->toBe("Action references unknown stepId 'missing'.");
});

it('flags workflow-level goto with unknown workflowId', function (): void {
    $goto = new SuccessGotoAction('jump', null, 'nonexistent-wf', []);
    $s = Fx::step('one', 'op');
    $wf = new Workflow(
        workflowId: 'wf',
        summary: null,
        description: null,
        inputs: null,
        dependsOn: [],
        steps: [$s],
        successActions: [$goto],
        failureActions: [],
        outputs: [],
        parameters: [],
    );
    $doc = Fx::doc(workflows: [$wf]);
    $ec = new ErrorCollector();

    (new ActionGotoTargetResolvesRule())->check($doc, SymbolTable::build($doc), $ec);

    expect($ec->errors())->toHaveCount(1)
        ->and($ec->errors()[0]->code)->toBe('action.goto_target_resolves')
        ->and($ec->errors()[0]->path)->toBe('/workflows/0/successActions/0/workflowId')
        ->and($ec->errors()[0]->message)->toBe("Action references unknown workflowId 'nonexistent-wf'.");
});

it('allows workflow-level goto to known targets', function (): void {
    $gotoStep = new SuccessGotoAction('to-step', 'one', null, []);
    $gotoWf = new FailureGotoAction('to-wf', null, 'other-wf', []);
    $s1 = Fx::step('one', 'op');
    $s2 = Fx::step('two', 'op');
    $wf1 = new Workflow(
        workflowId: 'wf1',
        summary: null,
        description: null,
        inputs: null,
        dependsOn: [],
        steps: [$s1],
        successActions: [$gotoStep],
        failureActions: [$gotoWf],
        outputs: [],
        parameters: [],
    );
    $wf2 = new Workflow(
        workflowId: 'other-wf',
        summary: null,
        description: null,
        inputs: null,
        dependsOn: [],
        steps: [$s2],
        successActions: [],
        failureActions: [],
        outputs: [],
        parameters: [],
    );
    $doc = Fx::doc(workflows: [$wf1, $wf2]);
    $ec = new ErrorCollector();

    (new ActionGotoTargetResolvesRule())->check($doc, SymbolTable::build($doc), $ec);

    expect($ec->errors())->toBeEmpty();
});

it('allows workflow-level end actions without targets', function (): void {
    $endSuccess = new SuccessEndAction('finish', []);
    $endFailure = new FailureEndAction('stop', []);
    $s = Fx::step('one', 'op');
    $wf = new Workflow(
        workflowId: 'wf',
        summary: null,
        description: null,
        inputs: null,
        dependsOn: [],
        steps: [$s],
        successActions: [$endSuccess],
        failureActions: [$endFailure],
        outputs: [],
        parameters: [],
    );
    $doc = Fx::doc(workflows: [$wf]);
    $ec = new ErrorCollector();

    (new ActionGotoTargetResolvesRule())->check($doc, SymbolTable::build($doc), $ec);

    expect($ec->errors())->toBeEmpty();
});

it('flags workflow-level retry action with unknown targets', function (): void {
    $retry = new RetryAction('retry-step', 1.0, 3, 'ghost-step', 'ghost-wf', []);
    $s = Fx::step('one', 'op');
    $wf = new Workflow(
        workflowId: 'wf',
        summary: null,
        description: null,
        inputs: null,
        dependsOn: [],
        steps: [$s],
        successActions: [],
        failureActions: [$retry],
        outputs: [],
        parameters: [],
    );
    $doc = Fx::doc(workflows: [$wf]);
    $ec = new ErrorCollector();

    (new ActionGotoTargetResolvesRule())->check($doc, SymbolTable::build($doc), $ec);

    expect($ec->errors())->toHaveCount(2)
        ->and($ec->errors()[0]->path)->toBe('/workflows/0/failureActions/0/stepId')
        ->and($ec->errors()[1]->path)->toBe('/workflows/0/failureActions/0/workflowId');
});
