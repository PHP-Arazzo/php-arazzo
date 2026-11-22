<?php

declare(strict_types=1);

namespace Alama\Arazzo\Contracts\Tests\Attributes;

use Alama\Arazzo\Contracts\Attributes\AsSelectorPlugin;
use Alama\Arazzo\Contracts\Spec\Enum\ExpressionType;

it('instantiates AsSelectorPlugin attribute with type and priority', function (): void {
    $attr = new AsSelectorPlugin(ExpressionType::JsonPath, priority: 10);

    expect($attr->type)->toBe(ExpressionType::JsonPath)
        ->and($attr->priority)->toBe(10);
});
