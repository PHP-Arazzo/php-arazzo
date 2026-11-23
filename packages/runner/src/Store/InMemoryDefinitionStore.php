<?php

declare(strict_types=1);

namespace Alama\Arazzo\Runner\Store;

use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Runner\DefinitionStoreInterface;

/**
 * Default in-memory {@see DefinitionStoreInterface} for local runs and tests.
 */
final class InMemoryDefinitionStore implements DefinitionStoreInterface
{
    /** @var array<string, ArazzoDocument> */
    private array $definitions = [];

    public function find(string $definitionId): ?ArazzoDocument
    {
        return $this->definitions[$definitionId] ?? null;
    }

    public function save(ArazzoDocument $document, ?string $definitionId = null): string
    {
        $id = $definitionId ?? 'in_memory_'.spl_object_id($document);
        $this->definitions[$id] = $document;

        return $id;
    }
}
