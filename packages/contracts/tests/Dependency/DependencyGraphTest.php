<?php

declare(strict_types=1);

namespace Alama\Arazzo\Tests\Execution;

use Alama\Arazzo\Contracts\Dependency\DependencyGraph;
use Alama\Arazzo\Contracts\Spec\Step;
use Alama\Arazzo\Contracts\Spec\StepFlow;
use Alama\Arazzo\Contracts\Spec\StepIo;
use Alama\Arazzo\Contracts\Spec\StepTarget;

it('computes topological order correctly', function (): void {
    $steps = [
        new Step('C', null, new StepTarget(), new StepFlow(dependsOn: ['A', 'B']), new StepIo()),
        new Step('A', null, new StepTarget(), new StepFlow(), new StepIo()),
        new Step('B', null, new StepTarget(), new StepFlow(dependsOn: ['A']), new StepIo()),
    ];

    $graph = new DependencyGraph($steps);

    expect($graph->getCycle())->toBeNull()
        ->and($graph->getUnresolvedReferences())->toBe([])
        ->and($graph->getTopologicalOrder())->toBe(['A', 'B', 'C']);
});

it('detects cycles correctly', function (): void {
    $steps = [
        new Step('A', null, new StepTarget(), new StepFlow(dependsOn: ['B']), new StepIo()),
        new Step('B', null, new StepTarget(), new StepFlow(dependsOn: ['C']), new StepIo()),
        new Step('C', null, new StepTarget(), new StepFlow(dependsOn: ['A']), new StepIo()),
    ];

    $graph = new DependencyGraph($steps);

    expect($graph->getCycle())->not->toBeNull()
        ->and($graph->getCycle())->toBe(['A', 'B', 'C', 'A']);
});

it('detects unresolved references correctly', function (): void {
    $steps = [
        new Step('A', null, new StepTarget(), new StepFlow(dependsOn: ['missing1', 'missing2']), new StepIo()),
        new Step('B', null, new StepTarget(), new StepFlow(dependsOn: ['A']), new StepIo()),
    ];

    $graph = new DependencyGraph($steps);

    expect($graph->getCycle())->toBeNull()
        ->and($graph->getUnresolvedReferences())->toBe([
            'A' => ['missing1', 'missing2'],
        ])
        ->and($graph->getTopologicalOrder())->toBe(['A', 'B']);
});

it('resolves transitive dependencies in topological order preserving document tie-breaking', function (): void {
    $steps = [
        new Step('unrelated', null, new StepTarget(), new StepFlow(), new StepIo()),
        new Step('A', null, new StepTarget(), new StepFlow(), new StepIo()),
        new Step('B', null, new StepTarget(), new StepFlow(dependsOn: ['A']), new StepIo()),
        new Step('C', null, new StepTarget(), new StepFlow(dependsOn: ['A']), new StepIo()),
        new Step('D', null, new StepTarget(), new StepFlow(dependsOn: ['B', 'C']), new StepIo()),
        new Step('E', null, new StepTarget(), new StepFlow(dependsOn: ['D']), new StepIo()),
    ];

    $graph = new DependencyGraph($steps);

    expect($graph->getTransitiveDependencies('A'))->toBe([])
        ->and($graph->getTransitiveDependencies('B'))->toBe(['A'])
        ->and($graph->getTransitiveDependencies('D'))->toBe(['A', 'B', 'C'])
        ->and($graph->getTransitiveDependencies('E'))->toBe(['A', 'B', 'C', 'D']);
});

it('resolves ties between independent dependencies using document order', function (): void {
    $stepsOrder1 = [
        new Step('A', null, new StepTarget(), new StepFlow(), new StepIo()),
        new Step('C', null, new StepTarget(), new StepFlow(dependsOn: ['A']), new StepIo()),
        new Step('B', null, new StepTarget(), new StepFlow(dependsOn: ['A']), new StepIo()),
        new Step('D', null, new StepTarget(), new StepFlow(dependsOn: ['B', 'C']), new StepIo()),
    ];

    $graph1 = new DependencyGraph($stepsOrder1);
    expect($graph1->getTransitiveDependencies('D'))->toBe(['A', 'C', 'B']);
});
