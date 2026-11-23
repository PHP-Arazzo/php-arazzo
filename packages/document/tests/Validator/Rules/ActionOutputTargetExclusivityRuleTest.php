<?php

declare(strict_types=1);

namespace Alama\Arazzo\Tests\Validation\Rules;

use Alama\Arazzo\Contracts\Spec\Expression;
use Alama\Arazzo\Contracts\Validation\ErrorCollector;
use Alama\Arazzo\Contracts\Validation\SymbolTable;
use Alama\Arazzo\Expression\Ast\ExpressionAst;
use Alama\Arazzo\Expression\Ast\SelfRef;
use Alama\Arazzo\Expression\Data\ExpressionReference;
use Alama\Arazzo\Expression\Enum\ReferenceKind;
use Alama\Arazzo\Expression\Exceptions\ExpressionSyntaxException;
use Alama\Arazzo\Expression\Interfaces\ParserInterface;
use Alama\Arazzo\Expression\Plugins\ValidationRule\ActionOutputTargetExclusivityRule;
use Alama\Arazzo\Tests\Support\Fx;

/**
 * Stub parser that lets ActionOutputTargetExclusivityRule tests control
 * parseOrError/projectReferences outcomes independently of the real parser
 * (which never yields null targets after a clean parse).
 */
final class StubRuleParser implements ParserInterface
{
    public function __construct(
        private readonly ?ExpressionSyntaxException $syntaxError = null,
        private readonly ?ExpressionReference $reference = null,
    ) {}

    public function parse(string $raw): ExpressionAst
    {
        return new SelfRef();
    }

    public function parseOrError(string $raw): ExpressionAst|ExpressionSyntaxException
    {
        return $this->syntaxError ?? new SelfRef();
    }

    public function projectReferences(string $raw): ?ExpressionReference
    {
        return $this->reference;
    }
}

it('accepts valid step output reference', function (): void {
    $step = Fx::step('fetch', 'op', outputs: ['result' => new Expression('{$steps.fetch.outputs.result}')]);
    $workflow = Fx::wf('w', [$step], outputs: ['out' => new Expression('{$steps.fetch.outputs.result}')]);
    $doc = Fx::doc(workflows: [$workflow]);
    $ec = new ErrorCollector();
    (new ActionOutputTargetExclusivityRule())->check($doc, SymbolTable::build($doc), $ec);
    expect($ec->errors())->toHaveCount(0);
});

it('accepts valid input reference', function (): void {
    $workflow = Fx::wf(
        'w',
        [],
        inputs: ['type' => 'object', 'properties' => ['username' => ['type' => 'string']]],
        outputs: ['out' => new Expression('{$inputs.username}')],
    );
    $doc = Fx::doc(workflows: [$workflow]);
    $ec = new ErrorCollector();
    (new ActionOutputTargetExclusivityRule())->check($doc, SymbolTable::build($doc), $ec);
    expect($ec->errors())->toHaveCount(0);
});

it('rejects malformed expression', function (): void {
    $workflow = Fx::wf('w', [], outputs: ['out' => new Expression('{$steps.')]);
    $doc = Fx::doc(workflows: [$workflow]);
    $ec = new ErrorCollector();
    (new ActionOutputTargetExclusivityRule())->check($doc, SymbolTable::build($doc), $ec);
    expect($ec->errors())->toHaveCount(1)
        ->and($ec->errors()[0]->code)->toBe('action.output_target_ambiguous');
});

it('rejects reference to non-existent step', function (): void {
    $workflow = Fx::wf('w', [], outputs: ['out' => new Expression('{$steps.missing.outputs.result}')]);
    $doc = Fx::doc(workflows: [$workflow]);
    $ec = new ErrorCollector();
    (new ActionOutputTargetExclusivityRule())->check($doc, SymbolTable::build($doc), $ec);
    expect($ec->errors())->toHaveCount(1)
        ->and($ec->errors()[0]->code)->toBe('action.output_target_ambiguous');
});

it('rejects reference to non-existent workflow', function (): void {
    $workflow = Fx::wf('w', [], outputs: ['out' => new Expression('{$workflows.ghost.outputs.foo}')]);
    $doc = Fx::doc(workflows: [$workflow]);
    $ec = new ErrorCollector();
    (new ActionOutputTargetExclusivityRule())->check($doc, SymbolTable::build($doc), $ec);
    expect($ec->errors())->toHaveCount(1)
        ->and($ec->errors()[0]->code)->toBe('action.output_target_ambiguous');
});

it('rejects reference to non-existent input', function (): void {
    $workflow = Fx::wf('w', [], inputs: [], outputs: ['out' => new Expression('{$inputs.missing}')]);
    $doc = Fx::doc(workflows: [$workflow]);
    $ec = new ErrorCollector();
    (new ActionOutputTargetExclusivityRule())->check($doc, SymbolTable::build($doc), $ec);
    expect($ec->errors())->toHaveCount(1)
        ->and($ec->errors()[0]->code)->toBe('action.output_target_ambiguous');
});

it('accepts null and scalar outputs', function (): void {
    $workflow = Fx::wf('w', [], outputs: ['out1' => null, 'out2' => 'literal', 'out3' => 42]);
    $doc = Fx::doc(workflows: [$workflow]);
    $ec = new ErrorCollector();
    (new ActionOutputTargetExclusivityRule())->check($doc, SymbolTable::build($doc), $ec);
    expect($ec->errors())->toHaveCount(0);
});

it('accepts workflow output reference to itself', function (): void {
    $workflow = Fx::wf('w', [], outputs: ['out' => new Expression('{$workflows.w.outputs.foo}')]);
    $doc = Fx::doc(workflows: [$workflow]);
    $ec = new ErrorCollector();
    (new ActionOutputTargetExclusivityRule())->check($doc, SymbolTable::build($doc), $ec);
    expect($ec->errors())->toHaveCount(0);
});

it('continues when workflow has no symbol table entry', function (): void {
    $workflow = Fx::wf('w', [], outputs: ['out' => new Expression('{$steps.x.outputs.y}')]);
    $doc = Fx::doc(workflows: [$workflow]);
    $symbols = new SymbolTable([], [], [], []);
    $ec = new ErrorCollector();
    (new ActionOutputTargetExclusivityRule())->check($doc, $symbols, $ec);
    expect($ec->errors())->toHaveCount(0);
});

it('continues when expression parses cleanly but yields no reference', function (): void {
    $workflow = Fx::wf('w', [], outputs: ['out' => new Expression('{$steps.x.outputs.y}')]);
    $doc = Fx::doc(workflows: [$workflow]);
    $parser = new StubRuleParser(syntaxError: null, reference: null);
    $ec = new ErrorCollector();
    (new ActionOutputTargetExclusivityRule($parser))->check($doc, SymbolTable::build($doc), $ec);
    expect($ec->errors())->toHaveCount(0);
});

it('accepts step reference to the current step (null stepId)', function (): void {
    $workflow = Fx::wf('w', [], outputs: ['out' => new Expression('{$steps.outputs.result}')]);
    $doc = Fx::doc(workflows: [$workflow]);
    $ref = new ExpressionReference(kind: ReferenceKind::Step, target: null);
    $parser = new StubRuleParser(syntaxError: null, reference: $ref);
    $ec = new ErrorCollector();
    (new ActionOutputTargetExclusivityRule($parser))->check($doc, SymbolTable::build($doc), $ec);
    expect($ec->errors())->toHaveCount(0);
});

it('accepts workflow reference with null workflowId', function (): void {
    $workflow = Fx::wf('w', [], outputs: ['out' => new Expression('{$workflows.outputs.foo}')]);
    $doc = Fx::doc(workflows: [$workflow]);
    $ref = new ExpressionReference(kind: ReferenceKind::Workflow, target: null);
    $parser = new StubRuleParser(syntaxError: null, reference: $ref);
    $ec = new ErrorCollector();
    (new ActionOutputTargetExclusivityRule($parser))->check($doc, SymbolTable::build($doc), $ec);
    expect($ec->errors())->toHaveCount(0);
});

it('accepts input reference with null inputName', function (): void {
    $workflow = Fx::wf('w', [], outputs: ['out' => new Expression('{$inputs}')]);
    $doc = Fx::doc(workflows: [$workflow]);
    $ref = new ExpressionReference(kind: ReferenceKind::Input, target: null);
    $parser = new StubRuleParser(syntaxError: null, reference: $ref);
    $ec = new ErrorCollector();
    (new ActionOutputTargetExclusivityRule($parser))->check($doc, SymbolTable::build($doc), $ec);
    expect($ec->errors())->toHaveCount(0);
});

it('accepts output reference with null declared output name', function (): void {
    $workflow = Fx::wf('w', [], outputs: ['out' => new Expression('{$outputs}')]);
    $doc = Fx::doc(workflows: [$workflow]);
    $ref = new ExpressionReference(kind: ReferenceKind::Output, target: null);
    $parser = new StubRuleParser(syntaxError: null, reference: $ref);
    $ec = new ErrorCollector();
    (new ActionOutputTargetExclusivityRule($parser))->check($doc, SymbolTable::build($doc), $ec);
    expect($ec->errors())->toHaveCount(0);
});

it('rejects output reference to undeclared output', function (): void {
    $workflow = Fx::wf('w', [], outputs: ['out' => new Expression('{$outputs.missing}')]);
    $doc = Fx::doc(workflows: [$workflow]);
    $ref = new ExpressionReference(kind: ReferenceKind::Output, target: 'missing');
    $parser = new StubRuleParser(syntaxError: null, reference: $ref);
    $symbols = SymbolTable::build($doc);
    $ec = new ErrorCollector();
    (new ActionOutputTargetExclusivityRule($parser))->check($doc, $symbols, $ec);
    expect($ec->errors())->toHaveCount(1)
        ->and($ec->errors()[0]->code)->toBe('action.output_target_ambiguous');
});

it('accepts output reference to declared output', function (): void {
    $workflow = Fx::wf('w', [], outputs: ['declared' => 'x', 'out' => new Expression('{$outputs.declared}')]);
    $doc = Fx::doc(workflows: [$workflow]);
    $ref = new ExpressionReference(kind: ReferenceKind::Output, target: 'declared');
    $parser = new StubRuleParser(syntaxError: null, reference: $ref);
    $symbols = SymbolTable::build($doc);
    $ec = new ErrorCollector();
    (new ActionOutputTargetExclusivityRule($parser))->check($doc, $symbols, $ec);
    expect($ec->errors())->toHaveCount(0);
});
