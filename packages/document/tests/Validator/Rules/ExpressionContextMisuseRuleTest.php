<?php

declare(strict_types=1);

namespace Alama\Arazzo\Tests\Validation\Rules;

use Alama\Arazzo\Contracts\Spec\Action\SuccessEndAction;
use Alama\Arazzo\Contracts\Spec\Enum\ParameterIn;
use Alama\Arazzo\Contracts\Spec\Expression;
use Alama\Arazzo\Contracts\Spec\Parameter;
use Alama\Arazzo\Contracts\Spec\StepFactory;
use Alama\Arazzo\Contracts\Spec\StepFlow;
use Alama\Arazzo\Contracts\Spec\StepIo;
use Alama\Arazzo\Contracts\Spec\SuccessCriterion;
use Alama\Arazzo\Contracts\Spec\Workflow;
use Alama\Arazzo\Contracts\Validation\ErrorCollector;
use Alama\Arazzo\Contracts\Validation\SymbolTable;
use Alama\Arazzo\Expression\Plugins\ValidationRule\ExpressionContextMisuseRule;
use Alama\Arazzo\Tests\Support\Fx;

it('flags HttpMetaRef in parameters context', function (): void {
    $s = Fx::step('s', 'op', params: [
        new Parameter('c', ParameterIn::Header, new Expression('{$statusCode}')),
    ]);
    $doc = Fx::doc(workflows: [Fx::wf('main', [$s])]);
    $ec = new ErrorCollector();
    (new ExpressionContextMisuseRule())->check($doc, SymbolTable::build($doc), $ec);
    expect($ec->errors())->toHaveCount(1);
});

it('allows runtime refs in outputs', function (): void {
    $s = Fx::step('s', 'op', outputs: ['o' => new Expression('{$statusCode}')]);
    $doc = Fx::doc(workflows: [Fx::wf('main', [$s])]);
    $ec = new ErrorCollector();
    (new ExpressionContextMisuseRule())->check($doc, SymbolTable::build($doc), $ec);
    expect($ec->errors())->toBe([]);
});

it('allows runtime refs in step action criteria and flags in workflow action criteria', function (): void {
    $stepAction = new SuccessEndAction('ok', [new SuccessCriterion(null, '$statusCode == 200', null)]);
    $wfAction = new SuccessEndAction('bad', [new SuccessCriterion(null, '$statusCode == 200', null)]);

    $step = StepFactory::http(
        's', null,
        new StepFlow(onSuccess: [$stepAction]),
        new StepIo(),
        operationId: 'op',
    );
    $wf = new Workflow(
        'main', null, null, null, [],
        [$step],
        [$wfAction],
        [], [], [],
    );
    $doc = Fx::doc(workflows: [$wf]);
    $ec = new ErrorCollector();
    (new ExpressionContextMisuseRule())->check($doc, SymbolTable::build($doc), $ec);

    expect($ec->errors())->toHaveCount(1)
        ->and($ec->errors()[0]->code)->toBe('expr.context_misuse')
        ->and($ec->errors()[0]->path)->toBe('/workflows/0/successActions/0/criteria/0/condition')
        ->and($ec->errors()[0]->message)->toContain("is not valid in context 'wf.successActions'");
});
