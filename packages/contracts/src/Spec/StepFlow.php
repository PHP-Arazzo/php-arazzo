<?php

declare(strict_types=1);

namespace Alama\Arazzo\Contracts\Spec;

use Alama\Arazzo\Contracts\Spec\Action\FailureAction;
use Alama\Arazzo\Contracts\Spec\Action\SuccessAction;

/**
 * The control/scheduling axis of a Step (D4).
 */
final readonly class StepFlow
{
    /**
     * @param  list<string>|StringList  $dependsOn
     * @param  list<SuccessAction|Reusable>|ActionCollection  $onSuccess
     * @param  list<FailureAction|Reusable>|ActionCollection  $onFailure
     * @param  list<FailureAction|Reusable>|ActionCollection  $onTimeout
     * @param  list<FailureAction|Reusable>|ActionCollection  $onCancel
     */
    public function __construct(
        array|StringList $dependsOn = [],
        public ?int $timeout = null, // duration in milliseconds
        public ?string $timeoutDuration = null, // ISO-8601 duration (1.2 proposal)
        array|ActionCollection $onSuccess = [],
        array|ActionCollection $onFailure = [],
        array|ActionCollection $onTimeout = [],
        array|ActionCollection $onCancel = [],
        public ?bool $strictValidation = null,
        public ?bool $idempotencyKey = null,
        public ?string $idempotencyHeader = null,
    ) {
        [$this->dependsOn, $this->dependsOnList] = self::resolveDependsOn($dependsOn);
        [$this->onSuccess, $this->successActions] = self::resolveActions($onSuccess);
        [$this->onFailure, $this->failureActions] = self::resolveActions($onFailure);
        [$this->onTimeout, $this->timeoutActions] = self::resolveActions($onTimeout);
        [$this->onCancel, $this->cancelActions] = self::resolveActions($onCancel);
    }

    /**
     * @param  list<string>|StringList  $dependsOn
     * @return array{list<string>, StringList}
     */
    private static function resolveDependsOn(array|StringList $dependsOn): array
    {
        if ($dependsOn instanceof StringList) {
            return [$dependsOn->all(), $dependsOn];
        }

        return [$dependsOn, StringList::fromArray($dependsOn)];
    }

    /**
     * @template T of SuccessAction|FailureAction|Reusable
     *
     * @param  list<T>|ActionCollection  $actions
     * @return array{list<T>, ActionCollection}
     */
    private static function resolveActions(array|ActionCollection $actions): array
    {
        if ($actions instanceof ActionCollection) {
            /** @var list<T> $all */
            $all = $actions->all();

            return [$all, $actions];
        }

        return [$actions, ActionCollection::fromArray($actions)];
    }

    /** @var list<string> */
    public array $dependsOn;

    public StringList $dependsOnList;

    /** @var list<SuccessAction|Reusable> */
    public array $onSuccess;

    public ActionCollection $successActions;

    /** @var list<FailureAction|Reusable> */
    public array $onFailure;

    public ActionCollection $failureActions;

    /** @var list<FailureAction|Reusable> */
    public array $onTimeout;

    public ActionCollection $timeoutActions;

    /** @var list<FailureAction|Reusable> */
    public array $onCancel;

    public ActionCollection $cancelActions;

    public function timeoutSeconds(): ?float
    {
        return $this->timeout !== null ? $this->timeout / 1000.0 : null;
    }

    public function hasTimeout(): bool
    {
        return $this->timeout !== null || $this->timeoutDuration !== null;
    }

    public function hasDependsOn(): bool
    {
        return $this->dependsOn !== [];
    }

    public function hasSuccessActions(): bool
    {
        return $this->onSuccess !== [];
    }

    public function hasFailureActions(): bool
    {
        return $this->onFailure !== [];
    }
}
