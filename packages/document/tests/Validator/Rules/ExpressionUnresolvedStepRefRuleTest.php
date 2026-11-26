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
use Alama\Arazzo\Expression\Ast\ExpressionAst;
use Alama\Arazzo\Expression\Ast\SelfRef;
use Alama\Arazzo\Expression\Data\ExpressionReference;
use Alama\Arazzo\Expression\Enum\ReferenceKind;
use Alama\Arazzo\Expression\Exceptions\ExpressionSyntaxException;
use Alama\Arazzo\Expression\Interfaces\ParserInterface;
use Alama\Arazzo\Expression\Plugins\ValidationRule\ExpressionUnresolvedStepRefRule;
use Alama\Arazzo\Tests\Support\Fx;

it('flags OutputPart referencing missing step output', function (): void {
    $s1 = Fx::step('first', 'op', outputs: ['y' => new Expression('{$inputs.userId}')]);
    $s2 = Fx::step('second', 'op', params: [
        new Parameter('x', ParameterIn::Query, new Expression('{$steps.first.outputs.ghost}')),
    ]);
    $doc = Fx::doc(workflows: [
        new Workflow('main', null, null,
            ['type' => 'object', 'properties' => ['userId' => []]],
            [], [$s1, $s2], [], [], [], []),
    ]);
    $ec = new ErrorCollector();
    (new ExpressionUnresolvedStepRefRule())->check($doc, SymbolTable::build($doc), $ec);
    expect($ec->errors())->toHaveCount(1);
});

it('flags reference to unknown stepId', function (): void {
    $s = Fx::step('a', 'op', params: [
        new Parameter('x', ParameterIn::Query, new Expression('{$steps.ghost.outputs.y}')),
    ]);
    $doc = Fx::doc(workflows: [Fx::wf('main', [$s])]);
    $ec = new ErrorCollector();
    (new ExpressionUnresolvedStepRefRule())->check($doc, SymbolTable::build($doc), $ec);
    expect($ec->errors())->toHaveCount(1);
});

it('allows backward output references without dependsOn as implicit dependencies', function (): void {
    $s1 = Fx::step('first', 'op', outputs: ['y' => new Expression('{$response.body#/id}')]);
    $s2 = Fx::step('second', 'op', params: [
        new Parameter('x', ParameterIn::Query, new Expression('{$steps.first.outputs.y}')),
    ]);
    $doc = Fx::doc(workflows: [Fx::wf('main', [$s1, $s2])]);
    $ec = new ErrorCollector();
    (new ExpressionUnresolvedStepRefRule())->check($doc, SymbolTable::build($doc), $ec);
    expect($ec->errors())->toBeEmpty()->and($ec->warnings())->toBeEmpty();
});

it('flags forward output references when the workflow uses no dependsOn', function (): void {
    $s1 = Fx::step('first', 'op', params: [
        new Parameter('x', ParameterIn::Query, new Expression('{$steps.second.outputs.y}')),
    ]);
    $s2 = Fx::step('second', 'op', outputs: ['y' => new Expression('{$response.body#/id}')]);
    $doc = Fx::doc(workflows: [Fx::wf('main', [$s1, $s2])]);
    $ec = new ErrorCollector();
    (new ExpressionUnresolvedStepRefRule())->check($doc, SymbolTable::build($doc), $ec);
    expect($ec->errors())->toHaveCount(1)->and($ec->warnings())->toBeEmpty();
});

it('warns instead of failing on forward references when the workflow uses dependsOn elsewhere', function (): void {
    $s0 = Fx::step('entry', 'op');
    $s0 = StepFactory::http($s0->stepId, null, new StepFlow(dependsOn: ['other']), new StepIo(), operationId: $s0->target->operationId);
    $s1 = Fx::step('first', 'op', params: [
        new Parameter('x', ParameterIn::Query, new Expression('{$steps.second.outputs.y}')),
    ]);
    $s2 = Fx::step('second', 'op', outputs: ['y' => new Expression('{$response.body#/id}')]);
    $doc = Fx::doc(workflows: [Fx::wf('main', [$s0, $s1, $s2], dep: ['other'])]);
    $ec = new ErrorCollector();
    (new ExpressionUnresolvedStepRefRule())->check($doc, SymbolTable::build($doc), $ec);
    expect($ec->errors())->toBeEmpty()
        ->and($ec->warnings())->toHaveCount(1)
        ->and($ec->warnings()[0]->code)->toBe('expr.forward_step_ref');
});

it('still flags a declared-but-missing output on an implicit backward dependency', function (): void {
    $s1 = Fx::step('first', 'op', outputs: ['y' => new Expression('{$response.body#/id}')]);
    $s2 = Fx::step('second', 'op', params: [
        new Parameter('x', ParameterIn::Query, new Expression('{$steps.first.outputs.ghost}')),
    ]);
    $doc = Fx::doc(workflows: [Fx::wf('main', [$s1, $s2])]);
    $ec = new ErrorCollector();
    (new ExpressionUnresolvedStepRefRule())->check($doc, SymbolTable::build($doc), $ec);
    expect($ec->errors())->toHaveCount(1);
});

it('skips expression site with no workflow context (null workflow)', function (): void {
    $wf = new Workflow('main', null, null, null, [], [], [], [], ['out' => new Expression('{$steps.x.outputs.y}')], []);
    $doc = Fx::doc(workflows: [$wf]);
    // Symbol table with no workflows -> $site->workflow is null during walk.
    $symbols = new SymbolTable([], [], [], []);
    $ec = new ErrorCollector();
    (new ExpressionUnresolvedStepRefRule())->check($doc, $symbols, $ec);
    expect($ec->errors())->toBe([]);
});

it('flags implicit current-step reference outside step context', function (): void {
    $wf = new Workflow('main', null, null, null, [], [], [], [], ['out' => new Expression('{$steps.x.outputs.y}')], []);
    $doc = Fx::doc(workflows: [$wf]);
    $ref = new ExpressionReference(kind: ReferenceKind::Step, target: null);
    $parser = new class($ref) implements ParserInterface
    {
        public function __construct(private readonly ExpressionReference $ref) {}

        public function parse(string $raw): ExpressionAst
        {
            return new SelfRef();
        }

        public function parseOrError(string $raw): ExpressionAst|ExpressionSyntaxException
        {
            return new SelfRef();
        }

        public function projectReferences(string $raw): ?ExpressionReference
        {
            return $this->ref;
        }
    };
    $ec = new ErrorCollector();
    (new ExpressionUnresolvedStepRefRule($parser))->check($doc, SymbolTable::build($doc), $ec);
    expect($ec->errors())->toHaveCount(1)
        ->and($ec->errors()[0]->code)->toBe('expr.unresolved_step_ref');
});

it('returns false from isForwardReference when current step id is null', function (): void {
    $s1 = Fx::step('first', 'op', outputs: ['y' => new Expression('{$response.body#/id}')]);
    $wf = new Workflow('main', null, null, null, [], [$s1], [], [], ['out' => new Expression('{$steps.first.outputs.y}')], []);
    $doc = Fx::doc(workflows: [$wf]);
    // Workflow-level output referencing existing step; currentStepId is null.
    $ref = new ExpressionReference(kind: ReferenceKind::Step, target: 'first');
    $parser = new class($ref) implements ParserInterface
    {
        public function __construct(private readonly ExpressionReference $ref) {}

        public function parse(string $raw): ExpressionAst
        {
            return new SelfRef();
        }

        public function parseOrError(string $raw): ExpressionAst|ExpressionSyntaxException
        {
            return new SelfRef();
        }

        public function projectReferences(string $raw): ?ExpressionReference
        {
            return $this->ref;
        }
    };
    $ec = new ErrorCollector();
    (new ExpressionUnresolvedStepRefRule($parser))->check($doc, SymbolTable::build($doc), $ec);
    expect($ec->errors())->toBe([]);
});

it('flags unresolved step reference in action criteria and allows resolvable reference', function (): void {
    $badAction = new SuccessEndAction(
        'goBad',
        [new SuccessCriterion(null, '$steps.ghost.outputs.x == 1', null)],
    );
    $goodAction = new SuccessEndAction(
        'goGood',
        [new SuccessCriterion(null, '$steps.one.outputs.status == "ok"', null)],
    );

    $s1 = Fx::step('one', 'op', outputs: ['status' => new Expression('{$response.body#/status}')]);
    $s2 = StepFactory::http(
        'two', null,
        new StepFlow(onSuccess: [$badAction, $goodAction]),
        new StepIo(),
        operationId: 'op',
    );

    $doc = Fx::doc(workflows: [Fx::wf('main', [$s1, $s2])]);
    $ec = new ErrorCollector();
    (new ExpressionUnresolvedStepRefRule())->check($doc, SymbolTable::build($doc), $ec);

    expect($ec->errors())->toHaveCount(1)
        ->and($ec->errors()[0]->code)->toBe('expr.unresolved_step_ref')
        ->and($ec->errors()[0]->path)->toBe('/workflows/0/steps/1/onSuccess/0/criteria/0/condition')
        ->and($ec->errors()[0]->message)->toContain("Expression references unknown step 'ghost'");
});
