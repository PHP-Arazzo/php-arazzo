<?php

declare(strict_types=1);

namespace Alama\Arazzo\Contracts\Spec;

/**
 * The data axis of a Step (D4).
 */
final readonly class StepIo
{
    /**
     * @param  array<Parameter|Reusable>  $parameters
     * @param  list<SuccessCriterion>  $successCriteria
     * @param  array<string,Expression|Selector|scalar|array<mixed>|null>  $outputs
     */
    public function __construct(
        public array $parameters = [],
        public ?RequestBody $requestBody = null,
        public array $successCriteria = [],
        public array $outputs = [],
    ) {}

    public function hasParameters(): bool
    {
        return $this->parameters !== [];
    }

    public function hasOutputs(): bool
    {
        return $this->outputs !== [];
    }

    public function hasSuccessCriteria(): bool
    {
        return $this->successCriteria !== [];
    }

    public function hasRequestBody(): bool
    {
        return $this->requestBody !== null;
    }

    public function findParameter(string $name): ?Parameter
    {
        foreach ($this->parameters as $parameter) {
            if ($parameter instanceof Parameter && $parameter->name === $name) {
                return $parameter;
            }
        }

        return null;
    }

    public function hasParameter(string $name): bool
    {
        return $this->findParameter($name) !== null;
    }
}
