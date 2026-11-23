<?php

declare(strict_types=1);

namespace Alama\Arazzo\Runner;

use Alama\Arazzo\Contracts\Spec\ArazzoDocument;

/**
 * Version-pinned Arazzo document store used by {@see AsyncRunner} workers
 * to reload workflow definitions across process boundaries without serializing
 * full ASTs into queue payloads.
 */
interface DefinitionStoreInterface
{
    /**
     * Look up a previously stored workflow definition by id.
     */
    public function find(string $definitionId): ?ArazzoDocument;

    /**
     * Store a workflow definition and return its assigned (or given) definition id.
     */
    public function save(ArazzoDocument $document, ?string $definitionId = null): string;
}
