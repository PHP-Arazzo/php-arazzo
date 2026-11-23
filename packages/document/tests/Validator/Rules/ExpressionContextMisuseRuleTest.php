<?php

declare(strict_types=1);

namespace Alama\Arazzo\Tests\Validation\Rules;

use Alama\Arazzo\Contracts\Spec\Enum\ParameterIn;
use Alama\Arazzo\Contracts\Spec\Expression;
use Alama\Arazzo\Contracts\Spec\Parameter;
use Alama\Arazzo\Contracts\Validation\ErrorCollector;
use Alama\Arazzo\Contracts\Validation\SymbolTable;
use Alama\Arazzo\Expression\ExpressionEngine;
use Alama\Arazzo\Expression\Validation\Rules\ExpressionContextMisuseRule;
use Alama\Arazzo\Tests\Support\Fx;

it('flags HttpMetaRef in parameters context', function (): void {
    $s = Fx::step('s', 'op', params: [
        new Parameter('c', ParameterIn::Header, new Expression('{$statusCode}')),
    ]);
    $doc = Fx::doc(workflows: [Fx::wf('main', [$s])]);
    $ec = new ErrorCollector();
    (new ExpressionContextMisuseRule(new ExpressionEngine()))->check($doc, SymbolTable::build($doc), $ec);
    expect($ec->errors())->toHaveCount(1);
});

it('allows runtime refs in outputs', function (): void {
    $s = Fx::step('s', 'op', outputs: ['o' => new Expression('{$statusCode}')]);
    $doc = Fx::doc(workflows: [Fx::wf('main', [$s])]);
    $ec = new ErrorCollector();
    (new ExpressionContextMisuseRule(new ExpressionEngine()))->check($doc, SymbolTable::build($doc), $ec);
    expect($ec->errors())->toBe([]);
});
