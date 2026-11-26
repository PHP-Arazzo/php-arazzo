<?php

declare(strict_types=1);

use Alama\Arazzo\Contracts\Spec\Expression;
use Alama\Arazzo\Expression\Data\EmbeddedSegment;
use Alama\Arazzo\Expression\Data\ExpressionReference;
use Alama\Arazzo\Expression\Data\ExpressionSite;
use Alama\Arazzo\Expression\Data\Token;
use Alama\Arazzo\Expression\Enum\ExpressionContext;
use Alama\Arazzo\Expression\Enum\ReferenceKind;
use Alama\Arazzo\Expression\Enum\TokenKind;

test('expression Data objects host domain predicates directly (Tell, Dont Ask)', function (): void {
    $dot = new Token(TokenKind::Dot, '.', 0);
    $name = new Token(TokenKind::Name, 'foo', 1);
    $kw = new Token(TokenKind::Keyword, 'body', 5);
    $seg = new Token(TokenKind::PointerSegment, '0', 10);

    expect($dot->isKind(TokenKind::Dot))->toBeTrue()
        ->and($dot->isIdentifier())->toBeFalse()
        ->and($name->isIdentifier())->toBeTrue()
        ->and($kw->isIdentifier())->toBeTrue()
        ->and($seg->isPointerSegment())->toBeTrue()
        ->and($name->isPointerSegment())->toBeTrue()
        ->and($dot->isPointerSegment())->toBeFalse();

    $lit = new EmbeddedSegment(false, 'hello', 0);
    $expr = new EmbeddedSegment(true, '$sourceDescriptions.api.url', 0);
    expect($lit->isLiteral())->toBeTrue()
        ->and($expr->isLiteral())->toBeFalse()
        ->and($expr->isSoleExpressionOf('{$sourceDescriptions.api.url}'))->toBeTrue()
        ->and($expr->isSoleExpressionOf('prefix{$sourceDescriptions.api.url}'))->toBeFalse();

    $srcUrl = new ExpressionReference(ReferenceKind::Source, target: 'api', name: 'url');
    $httpMeta = new ExpressionReference(ReferenceKind::HttpMeta, httpPart: 'statusCode');
    $stepResp = new ExpressionReference(ReferenceKind::Step, target: 's1', part: 'response');
    $stepOut = new ExpressionReference(ReferenceKind::Step, target: 's1', part: 'outputs');

    expect($srcUrl->isSourceUrl())->toBeTrue()
        ->and($srcUrl->isRuntimeHttpReference())->toBeFalse()
        ->and($httpMeta->isRuntimeHttpReference())->toBeTrue()
        ->and($stepResp->isRuntimeHttpReference())->toBeTrue()
        ->and($stepOut->isRuntimeHttpReference())->toBeFalse();

    $criteriaSite = new ExpressionSite('/p', new Expression('$statusCode'), null, 's1', ExpressionContext::Criteria);
    $paramSite = new ExpressionSite('/p', new Expression('$statusCode'), null, 's1', ExpressionContext::Parameters);

    expect($criteriaSite->allowsRuntimeHttpReference())->toBeTrue()
        ->and($paramSite->allowsRuntimeHttpReference())->toBeFalse();
});
