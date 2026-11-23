<?php

declare(strict_types=1);

use Alama\Arazzo\Contracts\Interfaces\OperationExecutorPluginInterface;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Step;
use Alama\Arazzo\Contracts\Spec\StepExecutionOutcome;
use Alama\Arazzo\Contracts\State\WorkflowContext;
use Alama\Arazzo\Document\Document;
use Alama\Arazzo\Evaluation\EvaluationEngine;
use Alama\Arazzo\Expression\ExpressionEngine;
use Alama\Arazzo\Runner\Async\AsyncExecutionGraphAssembler;
use Alama\Arazzo\Runner\Async\CorrelationResumer;
use Alama\Arazzo\Runner\Async\StepExecutionWorker;
use Alama\Arazzo\Runner\Execution\Data\AsyncConfig;
use Alama\Arazzo\Runner\Registries\OperationExecutorRegistry;

function assemblerStubPlugin(string $pluginName): OperationExecutorPluginInterface
{
    return new class($pluginName) implements OperationExecutorPluginInterface
    {
        public function __construct(
            private readonly string $pluginName,
        ) {}

        public function name(): string
        {
            return $this->pluginName;
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
            throw new LogicException('stub protocol plugin is never executed');
        }
    };
}

afterEach(function (): void {
    OperationExecutorRegistry::reset();
});

it('assembles a StepExecutionWorker and CorrelationResumer from AsyncConfig defaults', function (): void {
    $assembler = new AsyncExecutionGraphAssembler(
        Document::create(),
        new EvaluationEngine(expression: ExpressionEngine::create()),
        AsyncConfig::defaults(),
    );

    expect($assembler->buildWorker())->toBeInstanceOf(StepExecutionWorker::class)
        ->and($assembler->buildResumer())->toBeInstanceOf(CorrelationResumer::class);
});

it('snapshots global plugins and layers per-instance overrides without mutating the global registry', function (): void {
    $globalPlugin = assemblerStubPlugin('global-http');
    OperationExecutorRegistry::create()->register($globalPlugin);

    $localOverride = assemblerStubPlugin('local-custom');
    $config = AsyncConfig::defaults()->withOptions(
        AsyncConfig::defaults()->options->withExecutor($localOverride),
    );

    $assembler = new AsyncExecutionGraphAssembler(
        Document::create(),
        new EvaluationEngine(expression: ExpressionEngine::create()),
        $config,
    );

    $worker = $assembler->buildWorker();

    expect($worker)->toBeInstanceOf(StepExecutionWorker::class)
        ->and(OperationExecutorRegistry::create()->all())->toBe([$globalPlugin]);
});
