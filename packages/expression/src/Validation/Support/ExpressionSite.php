<?php

declare(strict_types=1);

namespace Alama\Arazzo\Expression\Validation\Support;

use Alama\Arazzo\Contracts\Spec\Expression;
use Alama\Arazzo\Contracts\Validation\WorkflowSymbols;

/**
 * @internal stays out of the advertised contract; not part of the public API surface
 */
final readonly class ExpressionSite
{
    /**
     * @param  'parameters'|'requestBody'|'criteria'|'outputs'|'onSuccess'|'onFailure'|'wf.parameters'|'wf.outputs'|'components'  $context
     */
    public function __construct(
        public string $pointer,
        public Expression $expression,
        public ?WorkflowSymbols $workflow,
        public ?string $currentStepId,
        public string $context,
    ) {}
}
