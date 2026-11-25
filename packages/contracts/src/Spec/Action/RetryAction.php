<?php

declare(strict_types=1);

namespace Alama\Arazzo\Contracts\Spec\Action;

use Alama\Arazzo\Contracts\Spec\Enum\ActionKind;
use Alama\Arazzo\Contracts\Spec\SuccessCriterion;

final readonly class RetryAction extends FailureAction
{
    /** @param list<SuccessCriterion> $criteria */
    public function __construct(
        string $name,
        public ?float $retryAfter,
        public ?int $retryLimit,
        public ?string $stepId,
        public ?string $workflowId,
        array $criteria,
    ) {
        parent::__construct($name, ActionKind::Retry, $criteria);
    }

    public function isStepTarget(): bool
    {
        return $this->stepId !== null;
    }

    public function isWorkflowTarget(): bool
    {
        return $this->workflowId !== null;
    }

    public function hasRetryLimit(): bool
    {
        return $this->retryLimit !== null;
    }

    public function hasRetryAfter(): bool
    {
        return $this->retryAfter !== null;
    }
}
