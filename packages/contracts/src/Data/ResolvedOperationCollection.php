<?php

declare(strict_types=1);

namespace Alama\Arazzo\Contracts\Data;

use Alama\Arazzo\Contracts\Spec\ResolvedOperation;
use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * @implements IteratorAggregate<string, ResolvedOperation>
 */
final readonly class ResolvedOperationCollection implements Countable, IteratorAggregate
{
    /**
     * @param  array<string, ResolvedOperation>  $operations
     */
    public function __construct(
        private array $operations = [],
    ) {}

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * @param  array<string, ResolvedOperation>  $operations
     */
    public static function fromArray(array $operations): self
    {
        return new self($operations);
    }

    public function get(string $reference): ?ResolvedOperation
    {
        return $this->operations[$reference] ?? null;
    }

    public function has(string $reference): bool
    {
        return isset($this->operations[$reference]);
    }

    public function forSource(string $sourceName): self
    {
        $filtered = array_filter(
            $this->operations,
            static fn (ResolvedOperation $op): bool => $op->source->name === $sourceName,
        );

        return new self($filtered);
    }

    public function toKnownOperations(): KnownOperations
    {
        $known = KnownOperations::none();
        foreach ($this->operations as $key => $operation) {
            $known = $known->withOperation($operation->source->name, $key);
        }

        return $known;
    }

    /**
     * @return array<string, ResolvedOperation>
     */
    public function all(): array
    {
        return $this->operations;
    }

    public function count(): int
    {
        return count($this->operations);
    }

    public function isEmpty(): bool
    {
        return $this->operations === [];
    }

    /**
     * @return Traversable<string, ResolvedOperation>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->operations);
    }
}
