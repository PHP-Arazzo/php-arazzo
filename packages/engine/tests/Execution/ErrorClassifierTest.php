<?php

declare(strict_types=1);

namespace Alama\Arazzo\Tests\Engine\Execution;

use Alama\Arazzo\Contracts\Exceptions\AuthoringException;
use Alama\Arazzo\Contracts\Exceptions\CriteriaException;
use Alama\Arazzo\Contracts\Exceptions\ProtocolException;
use Alama\Arazzo\Contracts\Exceptions\SchemaValidationException;
use Alama\Arazzo\Contracts\Exceptions\TransportException;
use Alama\Arazzo\Engine\Execution\ErrorClassifier;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use RuntimeException;

it('classifies typed domain exceptions into canonical failure categories', function (): void {
    $classifier = new ErrorClassifier();

    expect($classifier->classify(TransportException::timeout('https://api.com', 'GET')))->toBe('transport')
        ->and($classifier->classify(ProtocolException::malformedResponse('https://api.com', 'bad json')))->toBe('protocol')
        ->and($classifier->classify(CriteriaException::unmet('s1', '$statusCode == 200')))->toBe('criteria')
        ->and($classifier->classify(SchemaValidationException::forStep('s1', [])))->toBe('schema')
        ->and($classifier->classify(AuthoringException::preflightFailure('bad doc')))->toBe('authoring')
        ->and($classifier->classify(new RuntimeException('unexpected error')))->toBe('execution');
});

it('classifies network and client connection failures as transport category', function (): void {
    $classifier = new ErrorClassifier();

    $connRefused = new RuntimeException('cURL error 7: Failed to connect to localhost port 8080: Connection refused');
    expect($classifier->classify($connRefused))->toBe('transport');

    $timeout = new RuntimeException('cURL error 28: Operation timed out after 5000 milliseconds');
    expect($classifier->classify($timeout))->toBe('transport');

    $dns = new RuntimeException('Could not resolve host: invalid.domain');
    expect($classifier->classify($dns))->toBe('transport');

    $req = new Request('GET', 'https://example.com');
    $guzzleConnect = new ConnectException('Connection refused', $req);
    expect($classifier->classify($guzzleConnect))->toBe('transport');
});

it('normalizes raw throwables into typed TransportException instances', function (): void {
    $classifier = new ErrorClassifier();

    $raw = new RuntimeException('Connection refused by peer');
    $normalized = $classifier->toTransportException($raw, 'https://api.example.com/items', 'POST');

    expect($normalized)->toBeInstanceOf(TransportException::class)
        ->and($normalized->targetUrl)->toBe('https://api.example.com/items')
        ->and($normalized->requestMethod)->toBe('POST')
        ->and($normalized->getPrevious())->toBe($raw)
        ->and($normalized->getMessage())->toContain('refused');

    $alreadyTyped = TransportException::timeout('https://api.example.com/users', 'GET');
    $unchanged = $classifier->toTransportException($alreadyTyped, 'https://api.example.com/users', 'GET');
    expect($unchanged)->toBe($alreadyTyped);
});

it('classifies and preserves stable transport.* error codes through TransportException normalization', function (): void {
    $classifier = new ErrorClassifier();

    $policyError = new class('Redirect limit exceeded') extends RuntimeException
    {
        public function getErrorCode(): string
        {
            return 'transport.redirect_limit';
        }
    };

    expect($classifier->classify($policyError))->toBe('transport');

    $normalized = $classifier->toTransportException($policyError, 'https://api.example.com/redirect', 'GET');

    expect($normalized)->toBeInstanceOf(TransportException::class)
        ->and($normalized->errorCode)->toBe('transport.redirect_limit')
        ->and($normalized->getErrorCode())->toBe('transport.redirect_limit')
        ->and($normalized->targetUrl)->toBe('https://api.example.com/redirect')
        ->and($normalized->requestMethod)->toBe('GET')
        ->and($normalized->getPrevious())->toBe($policyError);

    $rateLimited = new class('Rate limited') extends RuntimeException
    {
        public function getErrorCode(): string
        {
            return 'transport.rate_limited';
        }
    };

    $normalizedRate = $classifier->toTransportException($rateLimited, 'https://api.example.com/items', 'POST');

    expect($normalizedRate)->toBeInstanceOf(TransportException::class)
        ->and($normalizedRate->errorCode)->toBe('transport.rate_limited')
        ->and($normalizedRate->getErrorCode())->toBe('transport.rate_limited');
});
