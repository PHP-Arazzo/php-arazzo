<?php

declare(strict_types=1);

namespace Alama\Arazzo\Contracts\Spec;

use Alama\Arazzo\Contracts\Spec\Action\FailureAction;
use Alama\Arazzo\Contracts\Spec\Action\SuccessAction;

/**
 * @internal stays out of the advertised contract; not part of the public API surface
 */
final readonly class Workflow
{
    public string $workflowId;

    public ?string $summary;

    public ?string $description;

    /** @var array<string,mixed>|null */
    public ?array $inputs;

    public StringList $dependsOn;

    public StepCollection $steps;

    public ActionCollection $successActions;

    public ActionCollection $failureActions;

    public OutputMap $outputs;

    public ParameterCollection $parameters;

    /**
     * @param  array<string,mixed>|null  $inputs
     * @param  array<string>|StringList  $dependsOn
     * @param  array<Step>|StepCollection  $steps
     * @param  array<SuccessAction|Reusable>|ActionCollection  $successActions
     * @param  array<FailureAction|Reusable>|ActionCollection  $failureActions
     * @param  array<string,Expression|Selector|scalar|array<mixed>|null>|OutputMap  $outputs
     * @param  array<Parameter|Reusable>|ParameterCollection  $parameters
     */
    public function __construct(
        string $workflowId,
        ?string $summary,
        ?string $description,
        ?array $inputs,
        array|StringList $dependsOn,
        array|StepCollection $steps,
        array|ActionCollection $successActions,
        array|ActionCollection $failureActions,
        array|OutputMap $outputs,
        array|ParameterCollection $parameters,
    ) {
        $this->workflowId = $workflowId;
        $this->summary = $summary;
        $this->description = $description;
        $this->inputs = $inputs;
        $this->dependsOn = $dependsOn instanceof StringList
            ? $dependsOn
            : StringList::fromArray($dependsOn);
        $this->steps = $steps instanceof StepCollection
            ? $steps
            : StepCollection::fromArray($steps);
        $this->successActions = $successActions instanceof ActionCollection
            ? $successActions
            : ActionCollection::fromArray($successActions);
        $this->failureActions = $failureActions instanceof ActionCollection
            ? $failureActions
            : ActionCollection::fromArray($failureActions);
        $this->outputs = $outputs instanceof OutputMap
            ? $outputs
            : OutputMap::fromArray($outputs);
        $this->parameters = $parameters instanceof ParameterCollection
            ? $parameters
            : ParameterCollection::fromArray($parameters);
    }

    public function findStep(string $stepId): ?Step
    {
        return $this->steps->findStep($stepId);
    }

    public function firstStep(): ?Step
    {
        return $this->steps->firstStep();
    }
}
