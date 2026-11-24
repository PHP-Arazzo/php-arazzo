<?php

declare(strict_types=1);

use Alama\Arazzo\Contracts\Support\Exceptions\ArazzoException;
use Alama\Arazzo\Engine\Exceptions\ExecutionException;
use Alama\Arazzo\Engine\Exceptions\InvalidRetryPolicyException;
use Alama\Arazzo\Engine\Exceptions\LockException;
use Alama\Arazzo\Engine\Exceptions\ParameterResolutionException;
use Alama\Arazzo\Engine\Exceptions\StateStoreException;
use Alama\Arazzo\Engine\Exceptions\TelemetrySetupException;
use Alama\Arazzo\Engine\Exceptions\TypeCastException;
use Alama\Arazzo\Runtime\Infrastructure\FileLockStrategy;
use Alama\Arazzo\Runtime\State\FileStateStore;

test('engine domain exception factories construct typed exceptions', function (): void {
    expect(ExecutionException::subWorkflowNotFound('child')->getMessage())->toContain('child')
        ->and(ExecutionException::messageFactoryMissing('s1')->getMessage())->toContain('s1')
        ->and(ExecutionException::unresolvableChannelTarget('s1', '/chan')->getMessage())->toContain('/chan')
        ->and(ExecutionException::noExecutorRegistry()->getMessage())->toContain('executor registry')
        ->and(ExecutionException::noSupportingExecutor('s1')->getMessage())->toContain('s1')
        ->and(ExecutionException::suspendedInSyncMode('s1')->getMessage())->toContain('s1')
        ->and(InvalidRetryPolicyException::nonPositiveMaxAttempts())->toBeInstanceOf(InvalidArgumentException::class)
        ->and(InvalidRetryPolicyException::invalidBackoffMultiplier()->getMessage())->toContain('backoffMultiplier')
        ->and(TypeCastException::toInteger())->toBeInstanceOf(InvalidArgumentException::class)
        ->and(TypeCastException::toFloat()->getMessage())->toContain('float')
        ->and(TypeCastException::toBoolean()->getMessage())->toContain('boolean')
        ->and(TypeCastException::toString()->getMessage())->toContain('string')
        ->and(StateStoreException::directoryCreationFailed('/tmp/nope'))->toBeInstanceOf(ArazzoException::class)
        ->and(StateStoreException::persistFailed('exec-1')->getMessage())->toContain('exec-1')
        ->and(StateStoreException::deleteFailed('exec-1')->getMessage())->toContain('exec-1')
        ->and(LockException::lockFileCreationFailed('/tmp/a.lock'))->toBeInstanceOf(ArazzoException::class)
        ->and(LockException::lockAcquisitionFailed('key-1')->getMessage())->toContain('key-1')
        ->and(TelemetrySetupException::traceFileOpenFailed('/tmp/trace.jsonl'))->toBeInstanceOf(ArazzoException::class)
        ->and(ParameterResolutionException::unresolvableReference('$components.parameters.missing'))->toBeInstanceOf(ArazzoException::class);
});

test('FileStateStore and FileLockStrategy throw typed domain exceptions when filesystem paths are unwritable', function (): void {
    expect(fn () => new FileStateStore('/dev/null/arazzo-cannot-create-dir'))
        ->toThrow(StateStoreException::class);

    $lock = new FileLockStrategy('/dev/null/arazzo-cannot-create-lock');
    expect(fn () => $lock->acquire('k', 5, static fn (): bool => true))
        ->toThrow(LockException::class)
        ->and($lock->tryAcquire('k', 5))->toBeFalse();
});
