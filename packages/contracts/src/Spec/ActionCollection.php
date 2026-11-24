<?php

declare(strict_types=1);

namespace Alama\Arazzo\Contracts\Spec;

use Alama\Arazzo\Contracts\Spec\Action\FailureAction;
use Alama\Arazzo\Contracts\Spec\Action\SuccessAction;
use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * @implements IteratorAggregate<int, SuccessAction|FailureAction|Reusable>
 */
final readonly class ActionCollection implements Countable, IteratorAggregate
{
    /**
     * @param  array<SuccessAction|FailureAction|Reusable>  $actions
     */
    public function __construct(
        private array $actions = [],
    ) {}

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * @param  array<SuccessAction|FailureAction|Reusable>  $actions
     */
    public static function fromArray(array $actions): self
    {
        return new self($actions);
    }

    /**
     * @return list<SuccessAction|FailureAction|Reusable>
     */
    public function all(): array
    {
        return array_values($this->actions);
    }

    public function count(): int
    {
        return count($this->actions);
    }

    public function isEmpty(): bool
    {
        return $this->actions === [];
    }

    /**
     * @return Traversable<int, SuccessAction|FailureAction|Reusable>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->actions);
    }
}
