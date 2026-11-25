<?php

declare(strict_types=1);

namespace Alama\Arazzo\Contracts\Spec;

use Alama\Arazzo\Contracts\Spec\Action\FailureAction;
use Alama\Arazzo\Contracts\Spec\Action\SuccessAction;

final readonly class Components
{
    /**
     * @param  array<string,array<string,mixed>>  $inputs
     * @param  array<string,Parameter>  $parameters
     * @param  array<string,SuccessAction>  $successActions
     * @param  array<string,FailureAction>  $failureActions
     * @param  array<string,Interaction>  $interactions
     */
    public function __construct(
        public array $inputs,
        public array $parameters,
        public array $successActions,
        public array $failureActions,
        public array $interactions = [],
    ) {}

    public function findParameter(string $name): ?Parameter
    {
        return $this->parameters[$name] ?? null;
    }

    public function findSuccessAction(string $name): ?SuccessAction
    {
        return $this->successActions[$name] ?? null;
    }

    public function findFailureAction(string $name): ?FailureAction
    {
        return $this->failureActions[$name] ?? null;
    }

    public function findInteraction(string $name): ?Interaction
    {
        return $this->interactions[$name] ?? null;
    }

    public function resolveReusableAction(Reusable $reusable): SuccessAction|FailureAction|null
    {
        $name = substr($reusable->reference, (int) strrpos($reusable->reference, '.') + 1);

        if (str_contains($reusable->reference, 'failureActions')) {
            return $this->findFailureAction($name);
        }

        return $this->findSuccessAction($name);
    }

    public function resolveReusableParameter(Reusable $reusable): ?Parameter
    {
        $name = substr($reusable->reference, (int) strrpos($reusable->reference, '.') + 1);

        return $this->findParameter($name);
    }

    public function hasParameter(string $name): bool
    {
        return isset($this->parameters[$name]);
    }

    public function hasAction(string $name): bool
    {
        return isset($this->successActions[$name]) || isset($this->failureActions[$name]);
    }

    public function isEmpty(): bool
    {
        return $this->inputs === []
            && $this->parameters === []
            && $this->successActions === []
            && $this->failureActions === []
            && $this->interactions === [];
    }
}
