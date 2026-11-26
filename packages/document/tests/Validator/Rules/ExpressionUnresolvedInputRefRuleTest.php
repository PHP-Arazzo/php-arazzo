<?php

declare(strict_types=1);

namespace Alama\Arazzo\Tests\Validation\Rules;

use Alama\Arazzo\Contracts\Spec\Action\SuccessGotoAction;
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
use Alama\Arazzo\Contracts\Validation\ErrorCollector;
use Alama\Arazzo\Contracts\Validation\SymbolTable;
use Alama\Arazzo\Expression\Plugins\ValidationRule\ExpressionUnresolvedInputRefRule;
use Alama\Arazzo\Tests\Support\Fx;

it('resolves via workflow.inputs or workflow.parameters and flags missing', function (): void {
    $wf = new Workflow(
        'main', null, null,
        ['type' => 'object', 'properties' => ['inputA' => []]],
        [],
        [Fx::step('s', 'op', params: [
            new Parameter('a', ParameterIn::Query, new Expression('{$inputs.inputA}')),
            new Parameter('b', ParameterIn::Query, new Expression('{$inputs.paramB}')),
            new Parameter('c', ParameterIn::Query, new Expression('{$inputs.missing}')),
        ])],
        [], [], [],
        [new Parameter('paramB', ParameterIn::Query, 'v')],
    );
    $doc = Fx::doc(workflows: [$wf]);
    $ec = new ErrorCollector();
    (new ExpressionUnresolvedInputRefRule())->check($doc, SymbolTable::build($doc), $ec);
    expect($ec->errors())->toHaveCount(1);
});

it('skips component-level input references (no workflow context)', function (): void {
    $wf = new Workflow(
        'main', null, null,
        null, [], [], [], [],
        ['out' => new Expression('{$inputs.x}')],
        [],
    );
    $doc = new ArazzoDocument(
        arazzo: '1.1.0',
        info: new Info('t', null, null, '1'),
        sourceDescriptions: [],
        workflows: [$wf],
        components: new Components([], [], [], []),
        specificationExtensions: [],
    );
    // Symbol table with no workflows -> $site->workflow is null during walk.
    $symbols = new SymbolTable([], [], [], []);
    $ec = new ErrorCollector();
    (new ExpressionUnresolvedInputRefRule())->check($doc, $symbols, $ec);
    expect($ec->errors())->toBe([]);
});

it('flags unresolved input reference in action parameters and accepts resolvable input', function (): void {
    $goto = new SuccessGotoAction(
        'go',
        null,
        'childWf',
        [],
        [
            new Parameter('x', null, new Expression('{$inputs.missing}')),
            new Parameter('y', null, new Expression('{$inputs.validInput}')),
        ],
    );
    $step = StepFactory::http(
        's', null,
        new StepFlow(onSuccess: [$goto]),
        new StepIo(),
        operationId: 'op',
    );
    $wf = new Workflow(
        'main', null, null,
        ['type' => 'object', 'properties' => ['validInput' => []]],
        [],
        [$step],
        [], [], [], [],
    );
    $doc = Fx::doc(workflows: [$wf]);
    $ec = new ErrorCollector();
    (new ExpressionUnresolvedInputRefRule())->check($doc, SymbolTable::build($doc), $ec);

    expect($ec->errors())->toHaveCount(1)
        ->and($ec->errors()[0]->code)->toBe('expr.unresolved_input_ref')
        ->and($ec->errors()[0]->path)->toBe('/workflows/0/steps/0/onSuccess/0/parameters/0/value')
        ->and($ec->errors()[0]->message)->toContain("Expression references unknown input 'missing'");
});
