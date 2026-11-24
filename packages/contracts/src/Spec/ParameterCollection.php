<?php

declare(strict_types=1);

namespace Alama\Arazzo\Contracts\Spec;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * @implements IteratorAggregate<int, Parameter|Reusable>
 */
final readonly class ParameterCollection implements Countable, IteratorAggregate
{
    /**
     * @param  array<Parameter|Reusable>  $parameters
     */
    public function __construct(
        private array $parameters = [],
    ) {}

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * @param  array<Parameter|Reusable>  $parameters
     */
    public static function fromArray(array $parameters): self
    {
        return new self($parameters);
    }

    /**
     * @return array<Parameter|Reusable>
     */
    public function all(): array
    {
        return $this->parameters;
    }

    public function count(): int
    {
        return count($this->parameters);
    }

    public function isEmpty(): bool
    {
        return $this->parameters === [];
    }

    /**
     * @return Traversable<int, Parameter|Reusable>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->parameters);
    }
}
