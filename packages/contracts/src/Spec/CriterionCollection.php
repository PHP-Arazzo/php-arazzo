<?php

declare(strict_types=1);

namespace Alama\Arazzo\Contracts\Spec;

use Alama\Arazzo\Contracts\Spec\Enum\CriterionType;
use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * @implements IteratorAggregate<int, SuccessCriterion>
 */
final readonly class CriterionCollection implements Countable, IteratorAggregate
{
    /**
     * @param  array<SuccessCriterion>  $criteria
     */
    public function __construct(
        private array $criteria = [],
    ) {}

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * @param  array<SuccessCriterion>  $criteria
     */
    public static function fromArray(array $criteria): self
    {
        return new self($criteria);
    }

    /**
     * @return list<SuccessCriterion>
     */
    public function all(): array
    {
        return array_values($this->criteria);
    }

    public function isEmpty(): bool
    {
        return $this->criteria === [];
    }

    public function count(): int
    {
        return count($this->criteria);
    }

    /**
     * @return Traversable<int, SuccessCriterion>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->criteria);
    }

    public function simpleCriteria(): self
    {
        return new self(array_values(array_filter(
            $this->criteria,
            static fn (SuccessCriterion $criterion): bool => $criterion->type === CriterionType::Simple,
        )));
    }

    public function regexCriteria(): self
    {
        return new self(array_values(array_filter(
            $this->criteria,
            static fn (SuccessCriterion $criterion): bool => $criterion->type === CriterionType::Regex,
        )));
    }

    public function jsonPathCriteria(): self
    {
        return new self(array_values(array_filter(
            $this->criteria,
            static fn (SuccessCriterion $criterion): bool => $criterion->type === CriterionType::JsonPath,
        )));
    }

    public function xpathCriteria(): self
    {
        return new self(array_values(array_filter(
            $this->criteria,
            static fn (SuccessCriterion $criterion): bool => $criterion->type === CriterionType::XPath,
        )));
    }
}
