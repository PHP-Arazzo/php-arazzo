<?php

declare(strict_types=1);

namespace Alama\Arazzo\Contracts\Spec;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * @implements IteratorAggregate<int, string>
 */
final readonly class StringList implements Countable, IteratorAggregate
{
    /**
     * @param  array<string>  $values
     */
    public function __construct(
        private array $values = [],
    ) {}

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * @param  array<string>  $values
     */
    public static function fromArray(array $values): self
    {
        return new self($values);
    }

    public function contains(string $value): bool
    {
        return in_array($value, $this->values, true);
    }

    /**
     * @return list<string>
     */
    public function all(): array
    {
        return array_values($this->values);
    }

    public function count(): int
    {
        return count($this->values);
    }

    public function isEmpty(): bool
    {
        return $this->values === [];
    }

    /**
     * @return Traversable<int, string>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->values);
    }
}
