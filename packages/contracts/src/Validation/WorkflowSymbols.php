<?php

declare(strict_types=1);

namespace Alama\Arazzo\Contracts\Validation;

final readonly class WorkflowSymbols
{
    /**
     * @param  array<string,true>  $inputs
     * @param  array<string,true>  $parameters
     * @param  array<string,StepSymbols>  $stepsById
     * @param  array<string,true>  $outputs
     * @param  array<string,true>  $dependsOn
     */
    public function __construct(
        public array $inputs,
        public array $parameters,
        public array $stepsById,
        public array $outputs,
        public array $dependsOn,
    ) {}

    public function hasStep(string $stepId): bool
    {
        return isset($this->stepsById[$stepId]);
    }

    public function hasInput(string $name): bool
    {
        return isset($this->inputs[$name]);
    }

    public function hasOutput(string $name): bool
    {
        return isset($this->outputs[$name]);
    }
}
