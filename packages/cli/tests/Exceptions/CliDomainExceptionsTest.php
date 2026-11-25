<?php

declare(strict_types=1);

use Alama\Arazzo\Cli\Exceptions\CliException;
use Alama\Arazzo\Cli\Exceptions\CliExecutionException;
use Alama\Arazzo\Cli\Exceptions\GeneratorApiException;

test('cli domain exception factories construct typed exceptions', function (): void {
    expect(CliExecutionException::maxQueuedStepsExceeded('exec-1', 10000))
        ->toBeInstanceOf(CliException::class)
        ->and(CliExecutionException::maxQueuedStepsExceeded('exec-1', 10000)->getMessage())
        ->toContain('exec-1')
        ->and(GeneratorApiException::apiError('Rate limited'))
        ->toBeInstanceOf(CliException::class)
        ->and(GeneratorApiException::apiError('Rate limited')->getMessage())
        ->toBe('API Error: Rate limited');
});
