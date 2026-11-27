<?php

declare(strict_types=1);

namespace Alama\Arazzo\Tests\Contracts\Exceptions;

use Alama\Arazzo\Contracts\Exceptions\ArazzoExecutionExceptionInterface;
use Alama\Arazzo\Contracts\Exceptions\AuthoringException;
use Alama\Arazzo\Contracts\Exceptions\CriteriaException;
use Alama\Arazzo\Contracts\Exceptions\ProtocolException;
use Alama\Arazzo\Contracts\Exceptions\TransportException;
use Alama\Arazzo\Contracts\Spec\StepExecutionOutcome;
use RuntimeException;

it('creates rich TransportException instances via static domain factories', function (): void {
    $prev = new RuntimeException('Connection timed out');
    $e = TransportException::timeout('https://api.example.com/orders', 'POST', $prev);

    expect($e)->toBeInstanceOf(ArazzoExecutionExceptionInterface::class)
        ->and($e->targetUrl)->toBe('https://api.example.com/orders')
        ->and($e->requestMethod)->toBe('POST')
        ->and($e->getPrevious())->toBe($prev)
        ->and($e->getMessage())->toContain('timed out')
        ->and($e->getMessage())->toContain('https://api.example.com/orders');

    $connRefused = TransportException::connectionRefused('https://api.example.com/users', 'GET');
    expect($connRefused->targetUrl)->toBe('https://api.example.com/users')
        ->and($connRefused->requestMethod)->toBe('GET')
        ->and($connRefused->getMessage())->toContain('refused');

    $netErr = TransportException::networkError('https://api.example.com/users', 'GET', 'DNS resolution failed');
    expect($netErr->getMessage())->toContain('DNS resolution failed');

    $rateLimited = TransportException::rateLimited('https://api.example.com/items', 'POST', 'Rate limit exceeded');
    expect($rateLimited->getMessage())->toContain('Rate limit exceeded');
});

it('creates ProtocolException instances via static domain factories', function (): void {
    $malformed = ProtocolException::malformedResponse('https://api.example.com/data', 'Invalid JSON body');
    expect($malformed)->toBeInstanceOf(ArazzoExecutionExceptionInterface::class)
        ->and($malformed->targetUrl)->toBe('https://api.example.com/data')
        ->and($malformed->getMessage())->toContain('Invalid JSON body');

    $typeMismatch = ProtocolException::contentTypeMismatch('https://api.example.com/data', 'application/json', 'text/html');
    expect($typeMismatch->getMessage())->toContain('application/json')
        ->and($typeMismatch->getMessage())->toContain('text/html');
});

it('creates CriteriaException and AuthoringException instances', function (): void {
    $criteria = CriteriaException::unmet('step1', '$statusCode == 200', 'status was null');
    expect($criteria)->toBeInstanceOf(ArazzoExecutionExceptionInterface::class)
        ->and($criteria->stepId)->toBe('step1')
        ->and($criteria->condition)->toBe('$statusCode == 200');

    $authoring = AuthoringException::preflightFailure('Invalid step definition');
    expect($authoring)->toBeInstanceOf(ArazzoExecutionExceptionInterface::class)
        ->and($authoring->getMessage())->toBe('Invalid step definition');
});

it('supports failed step execution outcome with null statusCode and preserved request', function (): void {
    $cause = TransportException::networkError('https://api.example.com/test', 'GET', 'Host unreachable');
    $outcome = StepExecutionOutcome::failed($cause, 'transport', ['method' => 'GET', 'url' => 'https://api.example.com/test']);

    expect($outcome->isFailed())->toBeTrue()
        ->and($outcome->isResolved())->toBeFalse()
        ->and($outcome->statusCode)->toBeNull()
        ->and($outcome->failureCategory)->toBe('transport')
        ->and($outcome->exception)->toBe($cause)
        ->and($outcome->request)->toBe(['method' => 'GET', 'url' => 'https://api.example.com/test']);

    $resolved = StepExecutionOutcome::resolved(200, ['out' => 'val'], ['id' => 1]);
    expect($resolved->isFailed())->toBeFalse()
        ->and($resolved->isResolved())->toBeTrue()
        ->and($resolved->statusCode)->toBe(200)
        ->and($resolved->exception)->toBeNull();
});
