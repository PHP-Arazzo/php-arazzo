<?php

declare(strict_types=1);

namespace Alama\Arazzo\Tests\Validation\Support;

use Alama\Arazzo\Contracts\Spec\Action\FailureEndAction;
use Alama\Arazzo\Contracts\Spec\Action\RetryAction;
use Alama\Arazzo\Contracts\Spec\Action\SubWorkflowSuccessAction;
use Alama\Arazzo\Contracts\Spec\Action\SuccessEndAction;
use Alama\Arazzo\Contracts\Spec\Action\SuccessGotoAction;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Components;
use Alama\Arazzo\Contracts\Spec\Enum\ParameterIn;
use Alama\Arazzo\Contracts\Spec\Expression;
use Alama\Arazzo\Contracts\Spec\Info;
use Alama\Arazzo\Contracts\Spec\Parameter;
use Alama\Arazzo\Contracts\Spec\PayloadReplacement;
use Alama\Arazzo\Contracts\Spec\RequestBody;
use Alama\Arazzo\Contracts\Spec\Reusable;
use Alama\Arazzo\Contracts\Spec\StepFactory;
use Alama\Arazzo\Contracts\Spec\StepFlow;
use Alama\Arazzo\Contracts\Spec\StepIo;
use Alama\Arazzo\Contracts\Spec\SuccessCriterion;
use Alama\Arazzo\Contracts\Spec\Workflow;
use Alama\Arazzo\Contracts\Validation\SymbolTable;
use Alama\Arazzo\Expression\Data\ExpressionSite;
use Alama\Arazzo\Expression\Enum\ExpressionContext;
use Alama\Arazzo\Expression\Support\ExpressionWalker;

it('walks every expression context', function (): void {
    $body = new RequestBody(
        null,
        new Expression('{$inputs.x}'),
        [new PayloadReplacement('/t', new Expression('{$inputs.y}'))],
    );
    $crit = new SuccessCriterion('{$inputs.ctx}', '{$inputs.cond}', null);
    $step = StepFactory::http(
        's', null,
        new StepFlow(),
        new StepIo(
            parameters: [new Parameter('p', ParameterIn::Query, new Expression('{$inputs.p}'))],
            requestBody: $body,
            successCriteria: [$crit],
            outputs: ['out' => new Expression('{$inputs.o}')],
        ),
        operationId: 'op',
    );
    $wf = new Workflow(
        'w', null, null,
        ['type' => 'object', 'properties' => ['p' => [], 'x' => [], 'y' => [], 'o' => [], 'ctx' => [], 'cond' => []]],
        [],
        [$step],
        [], [],
        ['wo' => new Expression('{$inputs.p}')],
        [new Parameter('wp', ParameterIn::Query, new Expression('{$inputs.p}'))],
    );
    $doc = new ArazzoDocument('1.0.0', new Info('T', null, null, '1'), [], [$wf], new Components([], [], [], []), []);

    $contexts = [];
    foreach ((new ExpressionWalker())->walk($doc, SymbolTable::build($doc)) as $site) {
        expect($site)->toBeInstanceOf(ExpressionSite::class);
        $contexts[] = $site->context;
    }
    expect($contexts)->toContain(ExpressionContext::WorkflowParameters)
        ->toContain(ExpressionContext::WorkflowOutputs)
        ->toContain(ExpressionContext::Parameters)
        ->toContain(ExpressionContext::RequestBody)
        ->toContain(ExpressionContext::Criteria)
        ->toContain(ExpressionContext::Outputs);
});

it('skips non-Expression values', function (): void {
    $body = new RequestBody(null, 'literal', [new PayloadReplacement('/t', 'literal')]);
    $step = StepFactory::http(
        's', null,
        new StepFlow(),
        new StepIo(
            parameters: [new Parameter('p', ParameterIn::Query, 'literal')],
            requestBody: $body,
            successCriteria: [new SuccessCriterion('plain-context', 'plain-condition', null)],
        ),
        operationId: 'op',
    );
    $wf = new Workflow(
        'w', null, null, null, [], [$step], [], [], [],
        [new Parameter('wp', ParameterIn::Query, 'literal')],
    );
    $doc = new ArazzoDocument('1.0.0', new Info('T', null, null, '1'), [], [$wf], new Components([], [], [], []), []);
    $count = 0;
    foreach ((new ExpressionWalker())->walk($doc, SymbolTable::build($doc)) as $_) {
        $count++;
    }
    expect($count)->toBe(0);
});

it('yields site for action criteria condition', function (): void {
    $action = new SuccessEndAction(
        'go',
        [new SuccessCriterion(null, '$steps.unknown.outputs.x == 1', null)],
    );
    $step = StepFactory::http(
        'one', null,
        new StepFlow(onSuccess: [$action]),
        new StepIo(),
        operationId: 'op',
    );
    $wf = new Workflow('wf', null, null, null, [], [$step], [], [], [], []);
    $doc = new ArazzoDocument('1.1.0', new Info('T', null, null, '1'), [], [$wf], new Components([], [], [], []), []);

    $sites = iterator_to_array((new ExpressionWalker())->walk($doc, SymbolTable::build($doc)), false);

    expect($sites)->toHaveCount(1)
        ->and($sites[0]->pointer)->toBe('/workflows/0/steps/0/onSuccess/0/criteria/0/condition')
        ->and($sites[0]->expression->raw)->toBe('$steps.unknown.outputs.x')
        ->and($sites[0]->context)->toBe(ExpressionContext::OnSuccess)
        ->and($sites[0]->currentStepId)->toBe('one');
});

it('yields site for action parameter values on goto and invoke actions', function (): void {
    $goto = new SuccessGotoAction(
        'go',
        null,
        'childWf',
        [],
        [
            new Parameter('x', null, new Expression('{$inputs.missing}')),
            new Reusable('$components.parameters.reusableParam'),
        ],
    );
    $invoke = new SubWorkflowSuccessAction(
        'callWf',
        'subWf',
        ['token' => new Expression('{$inputs.token}')],
        [],
    );
    $step = StepFactory::http(
        'one', null,
        new StepFlow(onSuccess: [$goto, $invoke]),
        new StepIo(),
        operationId: 'op',
    );
    $wf = new Workflow('wf', null, null, null, [], [$step], [], [], [], []);
    $doc = new ArazzoDocument('1.1.0', new Info('T', null, null, '1'), [], [$wf], new Components([], [], [], []), []);

    $sites = iterator_to_array((new ExpressionWalker())->walk($doc, SymbolTable::build($doc)), false);

    expect($sites)->toHaveCount(2)
        ->and($sites[0]->pointer)->toBe('/workflows/0/steps/0/onSuccess/0/parameters/0/value')
        ->and($sites[0]->expression->raw)->toBe('{$inputs.missing}')
        ->and($sites[0]->context)->toBe(ExpressionContext::OnSuccess)
        ->and($sites[0]->currentStepId)->toBe('one')
        ->and($sites[1]->pointer)->toBe('/workflows/0/steps/0/onSuccess/1/parameters/token')
        ->and($sites[1]->expression->raw)->toBe('{$inputs.token}')
        ->and($sites[1]->context)->toBe(ExpressionContext::OnSuccess)
        ->and($sites[1]->currentStepId)->toBe('one');
});

it('walks onFailure, onTimeout, and onCancel step actions', function (): void {
    $failAction = new FailureEndAction('fail', [new SuccessCriterion(null, '$statusCode >= 500', null)]);
    $timeoutAction = new FailureEndAction('timeout', [new SuccessCriterion(null, '$steps.prev.outputs.t == 0', null)]);
    $cancelAction = new RetryAction('retry', 2.0, 3, null, null, [new SuccessCriterion(null, '$statusCode == 503', null)]);

    $step = StepFactory::http(
        's1', null,
        new StepFlow(
            onFailure: [$failAction],
            onTimeout: [$timeoutAction],
            onCancel: [$cancelAction],
        ),
        new StepIo(),
        operationId: 'op',
    );
    $wf = new Workflow('wf', null, null, null, [], [$step], [], [], [], []);
    $doc = new ArazzoDocument('1.1.0', new Info('T', null, null, '1'), [], [$wf], new Components([], [], [], []), []);

    $sites = iterator_to_array((new ExpressionWalker())->walk($doc, SymbolTable::build($doc)), false);

    expect($sites)->toHaveCount(3)
        ->and($sites[0]->pointer)->toBe('/workflows/0/steps/0/onFailure/0/criteria/0/condition')
        ->and($sites[0]->expression->raw)->toBe('$statusCode')
        ->and($sites[0]->context)->toBe(ExpressionContext::OnFailure)
        ->and($sites[0]->currentStepId)->toBe('s1')
        ->and($sites[1]->pointer)->toBe('/workflows/0/steps/0/onTimeout/0/criteria/0/condition')
        ->and($sites[1]->expression->raw)->toBe('$steps.prev.outputs.t')
        ->and($sites[1]->context)->toBe(ExpressionContext::OnFailure)
        ->and($sites[1]->currentStepId)->toBe('s1')
        ->and($sites[2]->pointer)->toBe('/workflows/0/steps/0/onCancel/0/criteria/0/condition')
        ->and($sites[2]->expression->raw)->toBe('$statusCode')
        ->and($sites[2]->context)->toBe(ExpressionContext::OnFailure)
        ->and($sites[2]->currentStepId)->toBe('s1');
});

it('walks workflow-level successActions and failureActions with dedicated context and null stepId', function (): void {
    $wfSuccess = new SuccessGotoAction(
        'wfSuccessGo',
        null,
        'nextWf',
        [new SuccessCriterion(null, '$steps.final.outputs.status == "ok"', null)],
        [new Parameter('nextInput', null, new Expression('{$inputs.globalId}'))],
    );
    $wfFail = new FailureEndAction(
        'wfFailEnd',
        [new SuccessCriterion(null, '$steps.cleanup.outputs.done == true', null)],
    );

    $step = StepFactory::http('final', null, new StepFlow(), new StepIo(), operationId: 'op');
    $wf = new Workflow(
        'mainWf', null, null, null, [],
        [$step],
        [$wfSuccess],
        [$wfFail],
        [], [],
    );
    $doc = new ArazzoDocument('1.1.0', new Info('T', null, null, '1'), [], [$wf], new Components([], [], [], []), []);

    $sites = iterator_to_array((new ExpressionWalker())->walk($doc, SymbolTable::build($doc)), false);

    expect($sites)->toHaveCount(3)
        ->and($sites[0]->pointer)->toBe('/workflows/0/successActions/0/criteria/0/condition')
        ->and($sites[0]->expression->raw)->toBe('$steps.final.outputs.status')
        ->and($sites[0]->context)->toBe(ExpressionContext::WorkflowSuccessActions)
        ->and($sites[0]->currentStepId)->toBeNull()
        ->and($sites[1]->pointer)->toBe('/workflows/0/successActions/0/parameters/0/value')
        ->and($sites[1]->expression->raw)->toBe('{$inputs.globalId}')
        ->and($sites[1]->context)->toBe(ExpressionContext::WorkflowSuccessActions)
        ->and($sites[1]->currentStepId)->toBeNull()
        ->and($sites[2]->pointer)->toBe('/workflows/0/failureActions/0/criteria/0/condition')
        ->and($sites[2]->expression->raw)->toBe('$steps.cleanup.outputs.done')
        ->and($sites[2]->context)->toBe(ExpressionContext::WorkflowFailureActions)
        ->and($sites[2]->currentStepId)->toBeNull();
});
