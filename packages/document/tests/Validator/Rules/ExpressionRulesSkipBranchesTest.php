<?php

declare(strict_types=1);

namespace Alama\Arazzo\Tests\Validation\Rules;

use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Enum\ParameterIn;
use Alama\Arazzo\Contracts\Spec\Expression;
use Alama\Arazzo\Contracts\Spec\Parameter;
use Alama\Arazzo\Contracts\Spec\RequestBody;
use Alama\Arazzo\Contracts\Spec\Workflow;
use Alama\Arazzo\Contracts\Validation\ErrorCollector;
use Alama\Arazzo\Contracts\Validation\SymbolTable;
use Alama\Arazzo\Expression\Plugins\ValidationRule\ExpressionContextMisuseRule;
use Alama\Arazzo\Expression\Plugins\ValidationRule\ExpressionJsonPointerSyntaxRule;
use Alama\Arazzo\Expression\Plugins\ValidationRule\ExpressionUnresolvedComponentRefRule;
use Alama\Arazzo\Expression\Plugins\ValidationRule\ExpressionUnresolvedInputRefRule;
use Alama\Arazzo\Expression\Plugins\ValidationRule\ExpressionUnresolvedSourceRefRule;
use Alama\Arazzo\Expression\Plugins\ValidationRule\ExpressionUnresolvedStepRefRule;
use Alama\Arazzo\Expression\Plugins\ValidationRule\ExpressionUnresolvedWorkflowRefRule;
use Alama\Arazzo\Tests\Support\Fx;

/** Doc mixing broken expressions and unrelated AST types to exercise every rule's skip branches. */
function docWithMixedExpressions(): ArazzoDocument
{
    $wf = new Workflow(
        'main', null, null,
        ['type' => 'object', 'properties' => ['userId' => []]],
        [],
        [
            Fx::step('a', 'op', params: [
                new Parameter('broken', ParameterIn::Query, new Expression('{$broken')),
                new Parameter('input', ParameterIn::Query, new Expression('{$inputs.userId}')),
            ], body: new RequestBody(null, new Expression('{$inputs.userId}'), []), outputs: [
                'y' => new Expression('{$inputs.userId}'),
            ]),
        ],
        [], [], [], [],
    );

    return Fx::doc(workflows: [$wf]);
}

it('ExpressionUnresolvedInputRefRule skips syntax errors, non-InputRefs, and null workflow syms', function (): void {
    $doc = docWithMixedExpressions();
    $ec = new ErrorCollector();
    (new ExpressionUnresolvedInputRefRule())->check($doc, SymbolTable::build($doc), $ec);
    expect($ec->errors())->toBe([]);
});

it('ExpressionUnresolvedStepRefRule skips syntax errors and null workflow syms', function (): void {
    $doc = docWithMixedExpressions();
    $ec = new ErrorCollector();
    (new ExpressionUnresolvedStepRefRule())->check($doc, SymbolTable::build($doc), $ec);
    expect($ec->errors())->toBe([]);
});

it('ExpressionUnresolvedWorkflowRefRule skips syntax errors and non-workflow refs', function (): void {
    $doc = docWithMixedExpressions();
    $ec = new ErrorCollector();
    (new ExpressionUnresolvedWorkflowRefRule())->check($doc, SymbolTable::build($doc), $ec);
    expect($ec->errors())->toBe([]);
});

it('ExpressionUnresolvedSourceRefRule skips syntax errors and non-source refs', function (): void {
    $doc = docWithMixedExpressions();
    $ec = new ErrorCollector();
    (new ExpressionUnresolvedSourceRefRule())->check($doc, SymbolTable::build($doc), $ec);
    expect($ec->errors())->toBe([]);
});

it('ExpressionUnresolvedComponentRefRule skips syntax errors and non-component refs', function (): void {
    $doc = docWithMixedExpressions();
    $ec = new ErrorCollector();
    (new ExpressionUnresolvedComponentRefRule())->check($doc, SymbolTable::build($doc), $ec);
    expect($ec->errors())->toBe([]);
});

it('ExpressionContextMisuseRule skips syntax errors', function (): void {
    $doc = docWithMixedExpressions();
    $ec = new ErrorCollector();
    (new ExpressionContextMisuseRule())->check($doc, SymbolTable::build($doc), $ec);
    expect($ec->errors())->toBe([]);
});

it('ExpressionJsonPointerSyntaxRule skips syntax errors, non-step refs, non-http parts, and null pointers', function (): void {
    $wf = new Workflow(
        'main', null, null, null, [],
        [
            Fx::step('a', 'op', params: [
                new Parameter('broken', ParameterIn::Query, new Expression('{$broken')),
                new Parameter('input', ParameterIn::Query, new Expression('{$inputs.userId}')),
            ], body: new RequestBody(null, new Expression('{$steps.a.request.body}'), []), outputs: [
                'meta' => new Expression('{$statusCode}'),
            ]),
        ],
        [], [], [], [],
    );
    $doc = Fx::doc(workflows: [$wf]);
    $ec = new ErrorCollector();
    (new ExpressionJsonPointerSyntaxRule())->check($doc, SymbolTable::build($doc), $ec);
    expect($ec->errors())->toBe([]);
});
