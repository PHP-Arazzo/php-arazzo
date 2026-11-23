<?php

declare(strict_types=1);

namespace Alama\Arazzo\Contracts\Attributes;

use Alama\Arazzo\Contracts\Spec\Enum\OperationProtocol;
use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class AsOperationExecutorPlugin
{
    public function __construct(
        public OperationProtocol $protocol,
    ) {}
}
