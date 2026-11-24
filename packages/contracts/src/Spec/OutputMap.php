<?php

declare(strict_types=1);

namespace Alama\Arazzo\Contracts\Spec;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * @implements IteratorAggregate<string, Expression|Selector|scalar|array<mixed>|null>
 */
final readonly class OutputMap implements Countable, IteratorAggregate
{
    /**
     * @param  array<string, Expression|Selector|scalar|array<mixed>|null>  $outputs
     */
    public function __construct(
        private array $outputs = [],
    ) {}

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * @param  array<string, Expression|Selector|scalar|array<mixed>|null>  $outputs
     */
    public static function fromArray(array $outputs): self
    {
        return new self($outputs);
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->outputs);
    }

    public function get(string $name): mixed
    {
        return $this->outputs[$name] ?? null;
    }

    /**
     * @return array<string, Expression|Selector|scalar|array<mixed>|null>
     */
    public function all(): array
    {
        return $this->outputs;
    }

    public function count(): int
    {
        return count($this->outputs);
    }

    public function isEmpty(): bool
    {
        return $this->outputs === [];
    }

    /**
     * @return Traversable<string, Expression|Selector|scalar|array<mixed>|null>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->outputs);
    }
}
