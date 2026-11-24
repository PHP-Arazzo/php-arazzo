<?php

declare(strict_types=1);

namespace Alama\Arazzo\Contracts\Spec;

use Alama\Arazzo\Contracts\Spec\Enum\ParameterIn;
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

    public function findByName(string $name): ?Parameter
    {
        foreach ($this->parameters as $parameter) {
            if ($parameter instanceof Parameter && $parameter->name === $name) {
                return $parameter;
            }
        }

        return null;
    }

    public function has(string $name): bool
    {
        return $this->findByName($name) !== null;
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        $names = [];
        foreach ($this->parameters as $parameter) {
            if ($parameter instanceof Parameter) {
                $names[] = $parameter->name;
            }
        }

        return $names;
    }

    public function forLocation(ParameterIn $in): self
    {
        $filtered = array_filter(
            $this->parameters,
            static fn (Parameter|Reusable $param): bool => $param instanceof Parameter && $param->in === $in,
        );

        return new self(array_values($filtered));
    }
}
