<?php

declare(strict_types=1);

namespace Alama\Arazzo\Contracts\Spec;

/**
 * The data axis of a Step (D4).
 */
final readonly class StepIo
{
    /**
     * @param  array<Parameter|Reusable>|ParameterCollection  $parameters
     * @param  list<SuccessCriterion>|CriterionCollection  $successCriteria
     * @param  array<string,Expression|Selector|scalar|array<mixed>|null>|OutputMap  $outputs
     */
    public function __construct(
        array|ParameterCollection $parameters = [],
        public ?RequestBody $requestBody = null,
        array|CriterionCollection $successCriteria = [],
        array|OutputMap $outputs = [],
    ) {
        $this->parameters = $parameters instanceof ParameterCollection
            ? $parameters->all()
            : $parameters;
        $this->parameterCollection = $parameters instanceof ParameterCollection
            ? $parameters
            : ParameterCollection::fromArray($parameters);
        $this->successCriteria = $successCriteria instanceof CriterionCollection
            ? $successCriteria->all()
            : $successCriteria;
        $this->criteriaCollection = $successCriteria instanceof CriterionCollection
            ? $successCriteria
            : CriterionCollection::fromArray($successCriteria);
        $this->outputs = $outputs instanceof OutputMap
            ? $outputs->all()
            : $outputs;
        $this->outputMap = $outputs instanceof OutputMap
            ? $outputs
            : OutputMap::fromArray($outputs);
    }

    /** @var array<Parameter|Reusable> */
    public array $parameters;

    public ParameterCollection $parameterCollection;

    /** @var list<SuccessCriterion> */
    public array $successCriteria;

    public CriterionCollection $criteriaCollection;

    /** @var array<string,Expression|Selector|scalar|array<mixed>|null> */
    public array $outputs;

    public OutputMap $outputMap;

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
        return $this->parameterCollection->findByName($name);
    }

    public function hasParameter(string $name): bool
    {
        return $this->parameterCollection->has($name);
    }
}
