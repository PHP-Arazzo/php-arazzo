<?php

declare(strict_types=1);

namespace Alama\Arazzo\Contracts\Spec;

use Alama\Arazzo\Contracts\Spec\Enum\SourceType;
use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * @implements IteratorAggregate<int, SourceDescription>
 */
final readonly class SourceDescriptionCollection implements Countable, IteratorAggregate
{
    /**
     * @param  array<SourceDescription>  $sources
     */
    public function __construct(
        private array $sources = [],
    ) {}

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * @param  array<SourceDescription>  $sources
     */
    public static function fromArray(array $sources): self
    {
        return new self($sources);
    }

    public function findSource(string $name): ?SourceDescription
    {
        return array_find(
            $this->sources,
            static fn (SourceDescription $source): bool => $source->name === $name,
        );
    }

    public function hasSource(string $name): bool
    {
        return $this->findSource($name) !== null;
    }

    public function nonArazzo(): self
    {
        return new self(array_values(array_filter(
            $this->sources,
            static fn (SourceDescription $source): bool => $source->type !== SourceType::Arazzo,
        )));
    }

    public function hasExternalSourceFor(string $workflowId): bool
    {
        return array_any($this->sources, static function (SourceDescription $sourceDesc) use ($workflowId): bool {
            return $sourceDesc->type === SourceType::Arazzo && str_starts_with($workflowId, $sourceDesc->name.'.');
        });
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_values(array_map(
            static fn (SourceDescription $source): string => $source->name,
            $this->sources,
        ));
    }

    /**
     * @return list<SourceDescription>
     */
    public function all(): array
    {
        return array_values($this->sources);
    }

    public function isEmpty(): bool
    {
        return $this->sources === [];
    }

    public function count(): int
    {
        return count($this->sources);
    }

    /**
     * @return Traversable<int, SourceDescription>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->sources);
    }
}
