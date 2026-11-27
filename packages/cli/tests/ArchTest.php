<?php

declare(strict_types=1);
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;

arch('cli does not depend on illuminate framework')
    ->expect('Alama\Arazzo\Cli\Console')
    ->not->toUse('Illuminate')
    ->expect('Alama\Arazzo\Cli\Generator')
    ->not->toUse('Illuminate')
    ->expect('Alama\Arazzo\Cli\Renderer')
    ->not->toUse('Illuminate');

arch('cli composes only through the runner facade (F2.2)')
    ->expect('Alama\Arazzo\Cli')
    ->not->toUse([
        'Alama\Arazzo\Runner\Sync\StepExecutor',
        'Alama\Arazzo\Runner\Plugins\OperationExecutor\SubWorkflowStepExecutor',
        'Alama\Arazzo\Runner\Async\StepExecutionWorker',
        'Alama\Arazzo\Runner\Async\StepOutcomeHandler',
        'Alama\Arazzo\Runner\Async\CorrelationResumer',
        'Alama\Arazzo\Protocol\Http\Plugins\OperationExecutor\HttpStepExecutor',
        'Alama\Arazzo\Protocol\Http\Plugins\OperationExecutor\AsyncApiStepExecutor',
    ]);

arch('console command namespace contains only Symfony AsCommand classes')
    ->expect('Alama\Arazzo\Cli\Console\Command')
    ->classes()
    ->toExtend(Command::class)
    ->toHaveAttribute(AsCommand::class);
