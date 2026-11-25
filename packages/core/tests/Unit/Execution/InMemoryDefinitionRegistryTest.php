<?php

declare(strict_types=1);

namespace Tests\Unit\Execution;

use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Components;
use Alama\Arazzo\Contracts\Spec\Info;
use Alama\Arazzo\Runner\Store\InMemoryDefinitionStore;

function makeEmptyArazzoDocument(): ArazzoDocument
{
    return new ArazzoDocument(
        arazzo: '1.0.0',
        info: new Info('Test', null, null, '1.0.0'),
        sourceDescriptions: [],
        workflows: [],
        components: new Components([], [], [], []),
        specificationExtensions: [],
    );
}

it('saves and retrieves a document', function (): void {
    $store = new InMemoryDefinitionStore();
    $document = makeEmptyArazzoDocument();

    $id = $store->save($document);

    expect($store->find($id))->toBe($document);
});

it('returns null for an unknown id', function (): void {
    $store = new InMemoryDefinitionStore();

    expect($store->find('unknown'))->toBeNull();
});
