<?php

declare(strict_types=1);

use Alama\Arazzo\Engine\Execution\Data\InjectionResult;
use Alama\Arazzo\Engine\Execution\IdempotencyKeyInjector;
use Alama\Arazzo\Engine\Execution\ParameterSerializer;
use Alama\Arazzo\Engine\Execution\RequestCompiler;
use Alama\Arazzo\Engine\Execution\ReusableParameterResolver;
use Alama\Arazzo\Engine\Execution\StepParameterMerger;
use Alama\Arazzo\Engine\Execution\TypeCaster;
use Alama\Arazzo\Runner\AsyncRunner;
use Alama\Arazzo\Runner\Data\ExecuteStepJob;
use Alama\Arazzo\Runner\Data\ExecutionResult;
use Alama\Arazzo\Runner\Data\StepResult;
use Alama\Arazzo\Runner\Policy\ExecutionBudgets;
use Alama\Arazzo\Runner\Policy\ExecutionPolicy;
use Alama\Arazzo\Runner\Registries\OperationExecutorRegistry;
use Alama\Arazzo\Runner\Runner;

arch('runner public facades and DTOs are final and readonly')
    ->expect(Runner::class)
    ->toBeFinal()
    ->toBeReadonly()
    ->and(AsyncRunner::class)
    ->toBeFinal()
    ->toBeReadonly()
    ->and(ExecutionResult::class)
    ->toBeFinal()
    ->toBeReadonly()
    ->and(StepResult::class)
    ->toBeFinal()
    ->toBeReadonly()
    ->and(ExecuteStepJob::class)
    ->toBeFinal()
    ->toBeReadonly()
    ->and(ExecutionPolicy::class)
    ->toBeFinal()
    ->toBeReadonly()
    ->and(ExecutionBudgets::class)
    ->toBeFinal()
    ->toBeReadonly()
    ->and(OperationExecutorRegistry::class)
    ->toBeFinal();

arch('runner stays protocol-agnostic, transport-free, vendor-agnostic, and framework-free')
    ->expect('Alama\Arazzo\Runner')
    ->not->toUse([
        'Illuminate',
        'cebe\openapi',
        'GuzzleHttp',
        'Psr\Http',
        'OpenTelemetry',
        'Alama\Arazzo\Protocol',
        'Alama\Arazzo\Sources',
        'Alama\Arazzo\Cli',
    ]);

arch('runner does not leak expression internals')
    ->expect('Alama\Arazzo\Expression')
    ->not->toUse('Alama\Arazzo\Expression\EvaluationEngine');

arch('folded request pipeline stays transport-agnostic')
    ->expect(RequestCompiler::class)
    ->not->toUse(['Illuminate', 'cebe\openapi', 'GuzzleHttp', 'GuzzleHttp\Psr7'])
    ->expect(IdempotencyKeyInjector::class)
    ->not->toUse(['Illuminate', 'cebe\openapi', 'GuzzleHttp', 'GuzzleHttp\Psr7'])
    ->expect(ReusableParameterResolver::class)
    ->not->toUse(['Illuminate', 'cebe\openapi', 'GuzzleHttp', 'GuzzleHttp\Psr7'])
    ->expect(StepParameterMerger::class)
    ->not->toUse(['Illuminate', 'cebe\openapi', 'GuzzleHttp', 'GuzzleHttp\Psr7'])
    ->expect(ParameterSerializer::class)
    ->not->toUse(['Illuminate', 'cebe\openapi', 'GuzzleHttp', 'GuzzleHttp\Psr7'])
    ->expect(TypeCaster::class)
    ->not->toUse(['Illuminate', 'cebe\openapi', 'GuzzleHttp', 'GuzzleHttp\Psr7'])
    ->expect(InjectionResult::class)
    ->not->toUse(['Illuminate', 'cebe\openapi', 'GuzzleHttp', 'GuzzleHttp\Psr7']);

arch('sync path is queue-free')
    ->expect('Alama\Arazzo\Runner\Sync')
    ->not->toUse(['Alama\Arazzo\Runner\Async', 'Alama\Arazzo\Contracts\Interfaces\QueueDriverInterface']);

arch('async path may compose the sync path')
    ->expect('Alama\Arazzo\Runner\Async')
    ->toUse('Alama\Arazzo\Runner\Sync');

arch('nothing below the facade depends on the facades')
    ->expect('Alama\Arazzo\Runner\Sync')
    ->not->toUse([Runner::class, AsyncRunner::class])
    ->expect('Alama\Arazzo\Runner\Async')
    ->not->toUse([Runner::class, AsyncRunner::class]);
