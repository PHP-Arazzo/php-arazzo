<?php

declare(strict_types=1);

namespace Alama\Arazzo\Contracts\Tests\Attributes;

use Alama\Arazzo\Contracts\Attributes\AsSelectorPlugin;
use Alama\Arazzo\Contracts\Spec\Enum\ExpressionType;

it('instantiates AsSelectorPlugin attribute with ExpressionType enum', function (): void {
    $attr = new AsSelectorPlugin(ExpressionType::JsonPath);

    expect($attr->type)->toBe(ExpressionType::JsonPath);
});
