<?php

declare(strict_types=1);

namespace Alama\Arazzo\Tests\Validation\Rules;

use Alama\Arazzo\Contracts\Spec\Action\SuccessEndAction;
use Alama\Arazzo\Contracts\Spec\Expression;
use Alama\Arazzo\Contracts\Spec\StepFactory;
use Alama\Arazzo\Contracts\Spec\StepFlow;
use Alama\Arazzo\Contracts\Spec\StepIo;
use Alama\Arazzo\Contracts\Spec\SuccessCriterion;
use Alama\Arazzo\Contracts\Spec\Workflow;
use Alama\Arazzo\Contracts\Validation\ErrorCollector;
use Alama\Arazzo\Contracts\Validation\SymbolTable;
use Alama\Arazzo\Expression\Plugins\ValidationRule\ExpressionUnresolvedWorkflowRefRule;
use Alama\Arazzo\Tests\Support\Fx;

it('flags unknown workflow, not-in-dependsOn, missing inputs/outputs; accepts declared inputs/outputs', function (): void {
    $otherInputs = ['type' => 'object', 'properties' => ['i' => []]];
    $other = new Workflow('other', null, null, $otherInputs, [], [Fx::step()], [], [], [
        'o' => new Expression('{$inputs.i}'),
    ], []);
    $main = new Workflow('main', null, null, null, ['other'], [Fx::step()], [], [], [
        'ok' => new Expression('{$workflows.other.outputs.o}'),
        'bad' => new Expression('{$workflows.other.outputs.ghost}'),
        'okIn' => new Expression('{$workflows.other.inputs.i}'),
    ], []);
    $doc = Fx::doc(workflows: [$other, $main]);
    $ec = new ErrorCollector();
    (new ExpressionUnresolvedWorkflowRefRule())->check($doc, SymbolTable::build($doc), $ec);
    expect($ec->errors())->toHaveCount(1);
});

it('flags reference to workflow not in dependsOn', function (): void {
    $other = Fx::wf('other', [Fx::step()], [], null, ['o' => new Expression('{$inputs.x}')]);
    $main = new Workflow('main', null, null, null, [], [Fx::step()], [], [], [
        't' => new Expression('{$workflows.other.outputs.o}'),
    ], []);
    $doc = Fx::doc(workflows: [$other, $main]);
    $ec = new ErrorCollector();
    (new ExpressionUnresolvedWorkflowRefRule())->check($doc, SymbolTable::build($doc), $ec);
    expect($ec->errors())->toHaveCount(1);
});

it('flags unresolved workflow reference in action criteria and allows resolvable reference', function (): void {
    $other = new Workflow('other', null, null, ['type' => 'object', 'properties' => ['token' => []]], [], [Fx::step()], [], [], [], []);
    $actionBad = new SuccessEndAction('bad', [new SuccessCriterion(null, '$workflows.ghost.outputs.t == 1', null)]);
    $actionGood = new SuccessEndAction('good', [new SuccessCriterion(null, '$workflows.other.inputs.token != null', null)]);

    $step = StepFactory::http(
        's', null,
        new StepFlow(onSuccess: [$actionBad, $actionGood]),
        new StepIo(),
        operationId: 'op',
    );
    $main = new Workflow('main', null, null, null, ['other'], [$step], [], [], [], []);
    $doc = Fx::doc(workflows: [$other, $main]);

    $ec = new ErrorCollector();
    (new ExpressionUnresolvedWorkflowRefRule())->check($doc, SymbolTable::build($doc), $ec);

    expect($ec->errors())->toHaveCount(1)
        ->and($ec->errors()[0]->code)->toBe('expr.unresolved_workflow_ref')
        ->and($ec->errors()[0]->path)->toBe('/workflows/1/steps/0/onSuccess/0/criteria/0/condition')
        ->and($ec->errors()[0]->message)->toContain("Expression references unknown workflow 'ghost'");
});
