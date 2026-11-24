<?php

declare(strict_types=1);

namespace Alama\Arazzo\Contracts\Spec;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * @implements IteratorAggregate<int, Step>
 */
final readonly class StepCollection implements Countable, IteratorAggregate
{
    /**
     * @param  array<Step>  $steps
     */
    public function __construct(
        private array $steps = [],
    ) {}

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * @param  array<Step>  $steps
     */
    public static function fromArray(array $steps): self
    {
        return new self($steps);
    }

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

    /**
     * @return array<Step>
     */
    public function all(): array
    {
        return $this->steps;
    }

    public function count(): int
    {
        return count($this->steps);
    }

    public function isEmpty(): bool
    {
        return $this->steps === [];
    }

    /**
     * @return Traversable<int, Step>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->steps);
    }

    public function hasStep(string $stepId): bool
    {
        return $this->findStep($stepId) !== null;
    }

    /**
     * @return list<string>
     */
    public function stepIds(): array
    {
        return array_values(array_map(
            static fn (Step $step): string => $step->stepId,
            $this->steps,
        ));
    }
}
