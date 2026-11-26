<?php

declare(strict_types=1);

namespace Alama\Arazzo\Tests\Parser;

use Alama\Arazzo\Contracts\Spec\Action\SubWorkflowFailureAction;
use Alama\Arazzo\Contracts\Spec\Action\SubWorkflowSuccessAction;
use Alama\Arazzo\Contracts\Spec\Enum\CriterionType;
use Alama\Arazzo\Contracts\Spec\Enum\ParameterIn;
use Alama\Arazzo\Contracts\Spec\Enum\SourceType;
use Alama\Arazzo\Contracts\Spec\Expression;
use Alama\Arazzo\Document\Parser\Exceptions\ParserException;
use Alama\Arazzo\Document\Parser\ParseContext;
use Alama\Arazzo\Document\Parser\Parser;

class LeafProbe extends Parser
{
    public function pInfo(mixed $n, ParseContext $c)
    {
        return $this->parseInfo($n, $c);
    }

    public function pSrc(mixed $n, ParseContext $c)
    {
        return $this->parseSourceDescription($n, $c);
    }

    public function pParam(mixed $n, ParseContext $c)
    {
        return $this->parseParameter($n, $c);
    }

    public function pRepl(mixed $n, ParseContext $c)
    {
        return $this->parsePayloadReplacement($n, $c);
    }

    public function pReq(mixed $n, ParseContext $c)
    {
        return $this->parseRequestBody($n, $c);
    }

    public function pCrit(mixed $n, ParseContext $c)
    {
        return $this->parseSuccessCriterion($n, $c);
    }

    public function pReu(mixed $n, ParseContext $c)
    {
        return $this->parseReusable($n, $c);
    }

    public function pExpr(mixed $n)
    {
        return $this->parseExpressionOrValue($n);
    }

    public function pSuccAction(mixed $n, ParseContext $c)
    {
        return $this->parseSuccessAction($n, $c);
    }

    public function pFailAction(mixed $n, ParseContext $c)
    {
        return $this->parseFailureAction($n, $c);
    }
}

$ctx = fn () => new ParseContext('/x');

it('parses Info', function () use ($ctx): void {
    $i = (new LeafProbe())->pInfo(['title' => 'T', 'version' => '1.0'], $ctx());
    expect($i->title)->toBe('T')->and($i->version)->toBe('1.0')->and($i->summary)->toBeNull();
});

it('rejects Info missing version', function () use ($ctx): void {
    (new LeafProbe())->pInfo(['title' => 'T'], $ctx());
})->throws(ParserException::class);

it('parses SourceDescription', function () use ($ctx): void {
    $s = (new LeafProbe())->pSrc(['name' => 'api', 'url' => '/x', 'type' => 'openapi'], $ctx());
    expect($s->type)->toBe(SourceType::Openapi);
});

it('rejects bad source type', function () use ($ctx): void {
    (new LeafProbe())->pSrc(['name' => 'api', 'url' => '/x', 'type' => 'soap'], $ctx());
})->throws(ParserException::class);

it('parses SourceDescription without type (type is null)', function () use ($ctx): void {
    $s = (new LeafProbe())->pSrc(['name' => 'api', 'url' => '/x'], $ctx());
    expect($s->type)->toBeNull();
});

it('parses Parameter with expression value', function () use ($ctx): void {
    $p = (new LeafProbe())->pParam(['name' => 'id', 'in' => 'query', 'value' => '{$inputs.id}'], $ctx());
    expect($p->in)->toBe(ParameterIn::Query)
        ->and($p->value)->toBeInstanceOf(Expression::class);
});

it('parses scalar Parameter value', function () use ($ctx): void {
    $p = (new LeafProbe())->pParam(['name' => 'id', 'value' => 42], $ctx());
    expect($p->value)->toBe(42);
});

it('parses array Parameter value', function () use ($ctx): void {
    $p = (new LeafProbe())->pParam(['name' => 'id', 'value' => ['a' => 'b']], $ctx());
    expect($p->value)->toBe(['a' => 'b']);
});

it('parses SuccessCriterion with type', function () use ($ctx): void {
    $c = (new LeafProbe())->pCrit(['condition' => '$.id != null', 'type' => 'jsonpath', 'context' => '$response.body'], $ctx());
    expect($c->type)->toBe(CriterionType::JsonPath);
});

it('parses Reusable', function () use ($ctx): void {
    $r = (new LeafProbe())->pReu(['reference' => '$components.parameters.x'], $ctx());
    expect($r->reference)->toBe('$components.parameters.x');
});

it('detects expression strings or values', function (): void {
    expect((new LeafProbe())->pExpr('{$inputs.x}'))->toBeInstanceOf(Expression::class)
        ->and((new LeafProbe())->pExpr('plain'))->toBe('plain')
        ->and((new LeafProbe())->pExpr(5))->toBe(5)
        ->and((new LeafProbe())->pExpr(['a' => 1]))->toBe(['a' => 1])
        ->and((new LeafProbe())->pExpr(null))->toBeNull();
});

it('parses PayloadReplacement', function () use ($ctx): void {
    $r = (new LeafProbe())->pRepl(['target' => '$request.body.foo', 'value' => 'bar'], $ctx());
    expect($r->target)->toBe('$request.body.foo')
        ->and($r->value)->toBe('bar');
});

it('parses Parameter with canonical x- prefixed in extensions and bare extensions', function () use ($ctx): void {
    $pXBody = (new LeafProbe())->pParam(['name' => 'payload', 'in' => 'x-body', 'value' => 'bar'], $ctx());
    expect($pXBody->in)->toBe(ParameterIn::Body)
        ->and($pXBody->rawIn)->toBe('x-body')
        ->and($pXBody->isExtension())->toBeTrue();

    $pXQs = (new LeafProbe())->pParam(['name' => 'qs', 'in' => 'x-querystring', 'value' => 'a=1'], $ctx());
    expect($pXQs->in)->toBe(ParameterIn::Querystring)
        ->and($pXQs->rawIn)->toBe('x-querystring');

    $pXMeta = (new LeafProbe())->pParam(['name' => 'meta', 'in' => 'x-metadata', 'value' => 'm'], $ctx());
    expect($pXMeta->in)->toBe(ParameterIn::Metadata)
        ->and($pXMeta->rawIn)->toBe('x-metadata');

    $pXVar = (new LeafProbe())->pParam(['name' => 'var', 'in' => 'x-variable', 'value' => 'v'], $ctx());
    expect($pXVar->in)->toBe(ParameterIn::Variable)
        ->and($pXVar->rawIn)->toBe('x-variable');

    $pBare = (new LeafProbe())->pParam(['name' => 'b', 'in' => 'body', 'value' => 'bar'], $ctx());
    expect($pBare->in)->toBe(ParameterIn::Body)
        ->and($pBare->rawIn)->toBe('body')
        ->and($pBare->isExtension())->toBeTrue();
});

it('parses actions with canonical x-invoke and bare invoke type', function () use ($ctx): void {
    $probe = new LeafProbe();
    $succX = $probe->pSuccAction(['name' => 'callSub', 'type' => 'x-invoke', 'workflowId' => 'targetWf'], $ctx());
    expect($succX)->toBeInstanceOf(SubWorkflowSuccessAction::class)
        ->and($succX->rawType)->toBe('x-invoke')
        ->and($succX->isInvoke())->toBeTrue();

    $failX = $probe->pFailAction(['name' => 'failSub', 'type' => 'x-invoke', 'workflowId' => 'targetWf'], $ctx());
    expect($failX)->toBeInstanceOf(SubWorkflowFailureAction::class)
        ->and($failX->rawType)->toBe('x-invoke')
        ->and($failX->isInvoke())->toBeTrue();

    $succBare = $probe->pSuccAction(['name' => 'callSub', 'type' => 'invoke', 'workflowId' => 'targetWf'], $ctx());
    expect($succBare)->toBeInstanceOf(SubWorkflowSuccessAction::class)
        ->and($succBare->rawType)->toBe('invoke');
});

it('rejects PayloadReplacement missing value', function () use ($ctx): void {
    (new LeafProbe())->pRepl(['target' => '$request.body.foo'], $ctx());
})->throws(ParserException::class);

it('parses RequestBody without replacements', function () use ($ctx): void {
    $b = (new LeafProbe())->pReq(['contentType' => 'application/json', 'payload' => ['a' => 1]], $ctx());
    expect($b->contentType)->toBe('application/json')
        ->and($b->payload)->toBe(['a' => 1])
        ->and($b->replacements)->toBe([]);
});

it('parses RequestBody with replacements', function () use ($ctx): void {
    $b = (new LeafProbe())->pReq([
        'payload' => '{$inputs.body}',
        'replacements' => [['target' => '$request.body.foo', 'value' => 'bar']],
    ], $ctx());

    expect($b->payload)->toBeInstanceOf(Expression::class)
        ->and($b->replacements)->toHaveCount(1)
        ->and($b->replacements[0]->target)->toBe('$request.body.foo');
});
