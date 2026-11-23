<?php

declare(strict_types=1);

namespace Alama\Arazzo\Tests\Validation\Rules;

use Alama\Arazzo\Contracts\Spec\Expression;
use Alama\Arazzo\Contracts\Spec\RequestBody;
use Alama\Arazzo\Contracts\Validation\ErrorCollector;
use Alama\Arazzo\Contracts\Validation\SymbolTable;
use Alama\Arazzo\Expression\Ast\ExpressionAst;
use Alama\Arazzo\Expression\Ast\SelfRef;
use Alama\Arazzo\Expression\Data\ExpressionReference;
use Alama\Arazzo\Expression\Enum\ReferenceKind;
use Alama\Arazzo\Expression\Exceptions\ExpressionSyntaxException;
use Alama\Arazzo\Expression\Interfaces\ParserInterface;
use Alama\Arazzo\Expression\Plugins\ValidationRule\ExpressionJsonPointerSyntaxRule;
use Alama\Arazzo\Tests\Support\Fx;

it('flags bad pointer segment in request part; accepts valid response pointer', function (): void {
    $bodyOk = new RequestBody(null, new Expression('{$steps.a.request.body#/x}'), []);
    $s = Fx::step('a', 'op', body: $bodyOk, outputs: [
        'ok' => new Expression('{$steps.a.response.body#/ok}'),
    ]);
    $bodyBad = new RequestBody(null, new Expression('{$steps.a.request.body#/bad~9}'), []);
    $s2 = Fx::step('b', 'op', body: $bodyBad);
    $doc = Fx::doc(workflows: [Fx::wf('main', [$s, $s2])]);
    $ec = new ErrorCollector();
    (new ExpressionJsonPointerSyntaxRule())->check($doc, SymbolTable::build($doc), $ec);
    expect($ec->errors())->toHaveCount(1);
});

it('skips step output references (non request/response part)', function (): void {
    $s = Fx::step('a', 'op', outputs: [
        'ok' => new Expression('{$steps.a.outputs.x}'),
    ]);
    $doc = Fx::doc(workflows: [Fx::wf('main', [$s])]);
    // Stub parser returns a Step reference with part='outputs'.
    $ref = new ExpressionReference(kind: ReferenceKind::Step, target: 'a', part: 'outputs');
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
    (new ExpressionJsonPointerSyntaxRule($parser))->check($doc, SymbolTable::build($doc), $ec);
    expect($ec->errors())->toBe([]);
});
