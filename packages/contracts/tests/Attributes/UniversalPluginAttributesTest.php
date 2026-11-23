<?php

declare(strict_types=1);

namespace Alama\Arazzo\Contracts\Tests\Attributes;

use Alama\Arazzo\Contracts\Attributes\AsCriterionPlugin;
use Alama\Arazzo\Contracts\Attributes\AsExpressionPlugin;
use Alama\Arazzo\Contracts\Attributes\AsOperationExecutor;
use Alama\Arazzo\Contracts\Attributes\AsSourceNormalizer;
use Alama\Arazzo\Contracts\Attributes\AsValidationRule;
use Alama\Arazzo\Contracts\Spec\Enum\CriterionType;
use Alama\Arazzo\Contracts\Spec\Enum\ExpressionType;
use Alama\Arazzo\Contracts\Spec\Enum\OperationProtocol;
use Alama\Arazzo\Contracts\Spec\Enum\SourceType;
use Alama\Arazzo\Contracts\Validation\ValidationPhase;

it('instantiates AsSourceNormalizer attribute with SourceType enum', function (): void {
    $attr = new AsSourceNormalizer(SourceType::Openapi);
    expect($attr->type)->toBe(SourceType::Openapi);
});

it('instantiates AsOperationExecutor attribute with OperationProtocol enum', function (): void {
    $attr = new AsOperationExecutor(OperationProtocol::Soap);
    expect($attr->protocol)->toBe(OperationProtocol::Soap);
});

it('instantiates AsCriterionPlugin attribute with CriterionType enum', function (): void {
    $attr = new AsCriterionPlugin(CriterionType::JsonPath);
    expect($attr->type)->toBe(CriterionType::JsonPath);
});

it('instantiates AsExpressionPlugin attribute with ExpressionType enum', function (): void {
    $attr = new AsExpressionPlugin(ExpressionType::JsonPath);
    expect($attr->type)->toBe(ExpressionType::JsonPath);
});

it('instantiates AsValidationRule attribute with ValidationPhase enum defaulting to Semantic', function (): void {
    expect((new AsValidationRule())->phase)->toBe(ValidationPhase::Semantic)
        ->and((new AsValidationRule(ValidationPhase::Schema))->phase)->toBe(ValidationPhase::Schema);
});
