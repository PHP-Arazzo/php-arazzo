<?php

declare(strict_types=1);

namespace Alama\Arazzo\Contracts\Data;

final readonly class KnownOperations
{
    /**
     * @param  array<string, array<string, true>>  $operations  [sourceName => [operationId => true]]
     */
    public function __construct(
        private array $operations = [],
    ) {}

    public static function none(): self
    {
        return new self();
    }

    /**
     * @param  list<string>  $operationIds
     */
    public function withSource(string $source, array $operationIds): self
    {
        $ops = $this->operations;
        $ops[$source] ??= [];
        foreach ($operationIds as $id) {
            $ops[$source][$id] = true;
        }

        return new self($ops);
    }

    public function withOperation(string $source, string $operationId): self
    {
        $ops = $this->operations;
        $ops[$source][$operationId] = true;

        return new self($ops);
    }

    public function covers(string $source): bool
    {
        return array_key_exists($source, $this->operations);
    }

    public function has(string $source, string $operationId): bool
    {
        return isset($this->operations[$source][$operationId]);
    }

    /**
     * @return array<string, array<string, true>>
     */
    public function toArray(): array
    {
        return $this->operations;
    }
}
