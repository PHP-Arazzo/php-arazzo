<?php

declare(strict_types=1);

namespace Alama\Arazzo\Contracts\Spec;

use Alama\Arazzo\Contracts\Spec\Action\FailureAction;
use Alama\Arazzo\Contracts\Spec\Action\SuccessAction;

final readonly class Workflow
{
    /**
     * @param  array<string,mixed>|null  $inputs
     * @param  list<string>  $dependsOn
     * @param  list<Step>  $steps
     * @param  list<SuccessAction|Reusable>  $successActions
     * @param  list<FailureAction|Reusable>  $failureActions
     * @param  array<string,Expression|Selector|scalar|array<mixed>|null>  $outputs
     * @param  list<Parameter|Reusable>  $parameters
     */
    public function __construct(
        public string $workflowId,
        public ?string $summary,
        public ?string $description,
        public ?array $inputs,
        public array $dependsOn,
        public array $steps,
        public array $successActions,
        public array $failureActions,
        public array $outputs,
        public array $parameters,
    ) {}

    public function findStep(string $stepId): ?Step
    {
        return array_find(
            $this->steps,
            static fn (Step $step): bool => $step->stepId === $stepId,
        );
    }

    public function firstStep(): ?Step
    {
        return $this->steps[0] ?? null;
    }
}
