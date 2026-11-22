<?php

declare(strict_types=1);

use Alama\Arazzo\Contracts\Attributes\AsOperationExecutor;
use Alama\Arazzo\Contracts\Interfaces\OperationExecutorPluginInterface;
use Alama\Arazzo\Contracts\Interfaces\PluginInterface;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Components;
use Alama\Arazzo\Contracts\Spec\Info;
use Alama\Arazzo\Contracts\Spec\Step;
use Alama\Arazzo\Contracts\Spec\StepExecutionOutcome;
use Alama\Arazzo\Contracts\Spec\StepFlow;
use Alama\Arazzo\Contracts\Spec\StepIo;
use Alama\Arazzo\Contracts\Spec\StepTarget;
use Alama\Arazzo\Contracts\State\WorkflowContext;
use Alama\Arazzo\Runner\OperationExecutorRegistry;

class HighPriorityPlugin implements OperationExecutorPluginInterface, PluginInterface
{
    public function name(): string
    {
        return 'high';
    }

    public function priority(): int
    {
        return 10;
    }

    public function supports(Step $step, ArazzoDocument $document): bool
    {
        return str_contains($step->stepId, 'high');
    }

    public function execute(Step $step, WorkflowContext $context, ArazzoDocument $document, string $executionId): StepExecutionOutcome
    {
        return StepExecutionOutcome::resolved(200, [], []);
    }
}

class LowPriorityPlugin implements OperationExecutorPluginInterface, PluginInterface
{
    public function name(): string
    {
        return 'low';
    }

    public function priority(): int
    {
        return 100;
    }

    public function supports(Step $step, ArazzoDocument $document): bool
    {
        return true;
    }

    public function execute(Step $step, WorkflowContext $context, ArazzoDocument $document, string $executionId): StepExecutionOutcome
    {
        return StepExecutionOutcome::resolved(200, [], []);
    }
}

#[AsOperationExecutor(priority: 5)]
class AttributedExecutorPlugin implements OperationExecutorPluginInterface, PluginInterface
{
    public function name(): string
    {
        return 'attributed';
    }

    public function priority(): int
    {
        return 999;
    }

    public function supports(Step $step, ArazzoDocument $document): bool
    {
        return str_contains($step->stepId, 'attributed');
    }

    public function execute(Step $step, WorkflowContext $context, ArazzoDocument $document, string $executionId): StepExecutionOutcome
    {
        return StepExecutionOutcome::resolved(200, [], []);
    }
}

function registryDocument(): ArazzoDocument
{
    return new ArazzoDocument('1.0.0', new Info('Test', null, null, '1.0.0'), [], [], new Components([], [], [], []), []);
}

beforeEach(function (): void {
    OperationExecutorRegistry::reset();
});

it('operates as a singleton with create() and reset()', function (): void {
    $a = OperationExecutorRegistry::create();
    $b = OperationExecutorRegistry::create();

    expect($a)->toBe($b);

    OperationExecutorRegistry::reset();
    $c = OperationExecutorRegistry::create();

    expect($c)->not->toBe($a);
});

it('resolves the first plugin whose supports() returns true, ordered by priority', function (): void {
    $registry = OperationExecutorRegistry::create();
    $registry->register(new LowPriorityPlugin());
    $registry->register(new HighPriorityPlugin());

    $step = new Step('high-step', null, new StepTarget(), new StepFlow(), new StepIo());
    $resolved = $registry->resolve($step, registryDocument());

    expect($resolved)->not->toBeNull()
        ->and($resolved->name())->toBe('high');
});

it('returns null when no plugin supports the step', function (): void {
    $registry = OperationExecutorRegistry::create();
    $step = new Step('unknown', null, new StepTarget(), new StepFlow(), new StepIo());

    expect($registry->resolve($step, registryDocument()))->toBeNull();
});

it('returns all registered plugins', function (): void {
    $registry = OperationExecutorRegistry::create();
    $registry->register(new LowPriorityPlugin());
    $registry->register(new HighPriorityPlugin());

    expect($registry->all())->toHaveCount(2);
});

it('reads priority from #[AsOperationExecutor] attribute over priority() method', function (): void {
    $registry = OperationExecutorRegistry::create();
    $registry->register(new HighPriorityPlugin()); // priority 10
    $registry->register(new AttributedExecutorPlugin()); // attribute priority 5, priority() 999

    $step = new Step('high-attributed-step', null, new StepTarget(), new StepFlow(), new StepIo());
    $resolved = $registry->resolve($step, registryDocument());

    expect($resolved)->not->toBeNull()
        ->and($resolved->name())->toBe('attributed');
});
