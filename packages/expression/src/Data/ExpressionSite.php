<?php

declare(strict_types=1);

namespace Alama\Arazzo\Expression\Data;

use Alama\Arazzo\Contracts\Spec\Expression;
use Alama\Arazzo\Contracts\Validation\WorkflowSymbols;

/**
 * @internal stays out of the advertised contract; not part of the public API surface
 */
final readonly class ExpressionSite
{
    private const RUNTIME_HTTP_CONTEXTS = ['criteria', 'outputs', 'onSuccess', 'onFailure'];

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

    public function allowsRuntimeHttpReference(): bool
    {
        return in_array($this->context, self::RUNTIME_HTTP_CONTEXTS, true);
    }
}
