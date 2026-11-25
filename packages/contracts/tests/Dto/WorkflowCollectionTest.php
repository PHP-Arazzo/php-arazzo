<?php

declare(strict_types=1);

namespace Alama\Arazzo\Tests\Dto;

use Alama\Arazzo\Contracts\Spec\StepFactory;
use Alama\Arazzo\Contracts\Spec\StepFlow;
use Alama\Arazzo\Contracts\Spec\StepIo;
use Alama\Arazzo\Contracts\Spec\Workflow;
use Alama\Arazzo\Contracts\Spec\WorkflowCollection;

test('workflow collection hosts domain queries (Tell, Dont Ask)', function (): void {
    $s1 = StepFactory::http('s1', null, new StepFlow(), new StepIo(), operationId: 'getFoo');
    $s2 = StepFactory::http('s2', null, new StepFlow(), new StepIo(), operationId: 'listPets');
    $wf1 = new Workflow('main', null, null, null, [], [$s1], [], [], [], []);
    $wf2 = new Workflow('child', null, null, null, [], [$s2], [], [], [], []);

    $empty = WorkflowCollection::empty();
    expect($empty->count())->toBe(0)
        ->and($empty->isEmpty())->toBeTrue()
        ->and($empty->firstWorkflow())->toBeNull()
        ->and($empty->findWorkflow('main'))->toBeNull()
        ->and($empty->all())->toEqual([]);

    $collection = WorkflowCollection::fromArray([$wf1, $wf2]);
    expect($collection->count())->toBe(2)
        ->and($collection->isEmpty())->toBeFalse()
        ->and($collection->firstWorkflow())->toBe($wf1)
        ->and($collection->findWorkflow('main'))->toBe($wf1)
        ->and($collection->findWorkflow('child'))->toBe($wf2)
        ->and($collection->findWorkflow('missing'))->toBeNull()
        ->and($collection->all())->toEqual([$wf1, $wf2]);

    // byId index
    $byId = $collection->byId();
    expect($byId['main'])->toBe($wf1)
        ->and($byId['child'])->toBe($wf2)
        ->and($byId['missing'] ?? null)->toBeNull();

    // iterable
    $ids = [];
    foreach ($collection as $wf) {
        $ids[] = $wf->workflowId;
    }
    expect($ids)->toEqual(['main', 'child']);
});
