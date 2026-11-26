<?php

declare(strict_types=1);

namespace Alama\Arazzo\Expression\Data;

use Alama\Arazzo\Contracts\Spec\Expression;
use Alama\Arazzo\Contracts\Validation\WorkflowSymbols;
use Alama\Arazzo\Expression\Enum\ExpressionContext;

/**
 * @internal stays out of the advertised contract; not part of the public API surface
 */
final readonly class ExpressionSite
{
    public function __construct(
        public string $pointer,
        public Expression $expression,
        public ?WorkflowSymbols $workflow,
        public ?string $currentStepId,
        public ExpressionContext $context,
    ) {}

    public function allowsRuntimeHttpReference(): bool
    {
        return $this->context->allowsRuntimeHttpReference();
    }
}
