<?php

declare(strict_types=1);

namespace Alama\Arazzo\Contracts\Spec;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * @implements IteratorAggregate<int, Workflow>
 */
final readonly class WorkflowCollection implements Countable, IteratorAggregate
{
    /**
     * @param  array<Workflow>  $workflows
     */
    public function __construct(
        private array $workflows = [],
    ) {}

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * @param  array<Workflow>  $workflows
     */
    public static function fromArray(array $workflows): self
    {
        return new self($workflows);
    }

    public function findWorkflow(string $workflowId): ?Workflow
    {
        return array_find(
            $this->workflows,
            static fn (Workflow $workflow): bool => $workflow->workflowId === $workflowId,
        );
    }

    public function firstWorkflow(): ?Workflow
    {
        return $this->workflows[0] ?? null;
    }

    /**
     * @return array<Workflow>
     */
    public function all(): array
    {
        return $this->workflows;
    }

    public function count(): int
    {
        return count($this->workflows);
    }

    public function isEmpty(): bool
    {
        return $this->workflows === [];
    }

    /**
     * @return array<string, Workflow>
     */
    public function byId(): array
    {
        $indexed = [];
        foreach ($this->workflows as $workflow) {
            $indexed[$workflow->workflowId] = $workflow;
        }

        return $indexed;
    }

    /**
     * @return Traversable<int, Workflow>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->workflows);
    }

    /**
     * @return list<string> all workflow IDs in declaration order
     */
    public function allIds(): array
    {
        return array_values(array_map(
            static fn (Workflow $workflow): string => $workflow->workflowId,
            $this->workflows,
        ));
    }

    /**
     * Finds a step by ID across all workflows in declaration order.
     */
    public function findStepByWorkflow(string $stepId): ?Step
    {
        foreach ($this->workflows as $workflow) {
            $step = $workflow->findStep($stepId);
            if ($step !== null) {
                return $step;
            }
        }

        return null;
    }
}
