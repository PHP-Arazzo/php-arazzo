<?php

declare(strict_types=1);

namespace Alama\Arazzo\Contracts\Tests\Attributes;

use Alama\Arazzo\Contracts\Attributes\AsCriterionPlugin;
use Alama\Arazzo\Contracts\Attributes\AsExpressionPlugin;
use Alama\Arazzo\Contracts\Attributes\AsOperationExecutor;
use Alama\Arazzo\Contracts\Attributes\AsSourceNormalizer;
use Alama\Arazzo\Contracts\Spec\Enum\SourceType;

it('instantiates AsSourceNormalizer attribute', function (): void {
    $attr = new AsSourceNormalizer(SourceType::Openapi, priority: 10);
    expect($attr->type)->toBe(SourceType::Openapi)
        ->and($attr->priority)->toBe(10);
});

it('instantiates AsOperationExecutor attribute', function (): void {
    $attr = new AsOperationExecutor(priority: 5);
    expect($attr->priority)->toBe(5);
});

it('instantiates AsCriterionPlugin attribute', function (): void {
    $attr = new AsCriterionPlugin('jsonpath', priority: 0);
    expect($attr->type)->toBe('jsonpath')
        ->and($attr->priority)->toBe(0);
});

it('instantiates AsExpressionPlugin attribute', function (): void {
    $attr = new AsExpressionPlugin(priority: 0);
    expect($attr->priority)->toBe(0);
});
