<?php

declare(strict_types=1);

use Alama\Arazzo\Contracts\Attributes\AsOperationExecutorPlugin;
use Alama\Arazzo\Contracts\Interfaces\OperationExecutorPluginInterface;
use Alama\Arazzo\Contracts\Interfaces\PluginInterface;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Components;
use Alama\Arazzo\Contracts\Spec\Enum\OperationProtocol;
use Alama\Arazzo\Contracts\Spec\Info;
use Alama\Arazzo\Contracts\Spec\Step;
use Alama\Arazzo\Contracts\Spec\StepExecutionOutcome;
use Alama\Arazzo\Contracts\Spec\StepFlow;
use Alama\Arazzo\Contracts\Spec\StepIo;
use Alama\Arazzo\Contracts\Spec\StepTarget;
use Alama\Arazzo\Contracts\State\WorkflowContext;
use Alama\Arazzo\Runner\Registries\OperationExecutorRegistry;

#[AsOperationExecutorPlugin(OperationProtocol::SubWorkflow)]
class SubWorkflowProtocolPlugin implements OperationExecutorPluginInterface, PluginInterface
{
    public function name(): string
    {
        return 'sub-workflow';
    }

    public function priority(): int
    {
        return 0;
    }

    public function supports(Step $step, ArazzoDocument $document): bool
    {
        return str_contains($step->stepId, 'sub');
    }

    public function execute(Step $step, WorkflowContext $context, ArazzoDocument $document, string $executionId): StepExecutionOutcome
    {
        return StepExecutionOutcome::resolved(200, [], []);
    }
}

#[AsOperationExecutorPlugin(OperationProtocol::Http)]
class HttpProtocolPlugin implements OperationExecutorPluginInterface, PluginInterface
{
    public function name(): string
    {
        return 'http';
    }

    public function priority(): int
    {
        return 0;
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

#[AsOperationExecutorPlugin(OperationProtocol::Http)]
class CustomHttpOverridePlugin implements OperationExecutorPluginInterface, PluginInterface
{
    public function name(): string
    {
        return 'custom-http';
    }

    public function priority(): int
    {
        return 0;
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

function registryDocument(): ArazzoDocument
{
    return new ArazzoDocument('1.0.0', new Info('Test', null, null, '1.0.0'), [], [], new Components([], [], [], []), []);
}

beforeEach(function (): void {
    OperationExecutorRegistry::reset();
});

afterEach(function (): void {
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

it('resolves plugins in canonical OperationProtocol enum order', function (): void {
    $registry = OperationExecutorRegistry::create();
    $registry->register(new HttpProtocolPlugin());
    $registry->register(new SubWorkflowProtocolPlugin());

    $step = new Step('sub-step', null, new StepTarget(), new StepFlow(), new StepIo());
    $resolved = $registry->resolve($step, registryDocument());

    expect($resolved)->not->toBeNull()
        ->and($resolved->name())->toBe('sub-workflow');
});

it('returns null when no plugin supports the step', function (): void {
    $registry = OperationExecutorRegistry::create();
    $step = new Step('unknown', null, new StepTarget(), new StepFlow(), new StepIo());

    expect($registry->resolve($step, registryDocument()))->toBeNull();
});

it('returns all registered plugins', function (): void {
    $registry = OperationExecutorRegistry::create();
    $registry->register(new HttpProtocolPlugin());
    $registry->register(new SubWorkflowProtocolPlugin());

    expect($registry->all())->toHaveCount(2);
});

it('replaces existing plugin for the same OperationProtocol (last-registered wins)', function (): void {
    $registry = OperationExecutorRegistry::create();
    $registry->register(new HttpProtocolPlugin());
    $override = new CustomHttpOverridePlugin();
    $registry->register($override);

    $step = new Step('plain-step', null, new StepTarget(), new StepFlow(), new StepIo());
    $resolved = $registry->resolve($step, registryDocument());

    expect($resolved)->toBe($override)
        ->and($registry->all())->toHaveCount(1);
});
