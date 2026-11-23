<?php

declare(strict_types=1);

use Alama\Arazzo\Contracts\Clock\SystemClock;
use Alama\Arazzo\Contracts\Observability\NullSpan;
use Alama\Arazzo\Contracts\Observability\NullTracer;
use Alama\Arazzo\Contracts\Policy\Credential;
use Alama\Arazzo\Contracts\Policy\CredentialType;
use Alama\Arazzo\Contracts\Policy\ServerTarget;
use Alama\Arazzo\Contracts\Policy\SourcePolicy;
use Alama\Arazzo\Contracts\Policy\SourceTransportPolicy;
use Alama\Arazzo\Contracts\State\WorkflowContext;

it('provides a PSR-20 SystemClock returning DateTimeImmutable', function (): void {
    $clock = new SystemClock();

    expect($clock->now())->toBeInstanceOf(DateTimeImmutable::class);
});

it('provides a zero-overhead NullTracer and NullSpan', function (): void {
    $tracer = new NullTracer();
    $span = $tracer->startSpan('arazzo.test', ['k' => 'v']);

    expect($span)->toBeInstanceOf(NullSpan::class);

    $span->setAttribute('a', 1);
    $span->addEvent('evt', ['ok' => true]);
    $span->recordException(new RuntimeException('boom'));
    $span->setStatus(false, 'boom');
    $span->end();

    $carrier = [];
    $tracer->injectContext($carrier);
    expect($carrier)->toBe([])
        ->and($tracer->extractContext(['traceparent' => '00-abc-def-01']))->toBeNull();
});

it('builds immutable SourcePolicy, ServerTarget, SourceTransportPolicy, and secret-redacting Credentials', function (): void {
    $defaultTarget = ServerTarget::default();
    $defaultTransport = SourceTransportPolicy::default();

    expect($defaultTarget->url)->toBeNull()
        ->and($defaultTarget->variables)->toBe([])
        ->and($defaultTransport->timeoutSeconds)->toBeNull()
        ->and($defaultTransport->strictValidation)->toBeNull();

    $apiKey = Credential::apiKey('secret-key');
    $basic = Credential::basic('alice', 'super-secret');
    $bearer = Credential::bearer('tok-123');
    $oauth = Credential::oauth2('access-456', 'MAC');

    expect($apiKey->type)->toBe(CredentialType::ApiKey)
        ->and($apiKey->__debugInfo()['value'])->toBe('[REDACTED]')
        ->and($basic->__debugInfo()['password'])->toBe('[REDACTED]')
        ->and($basic->__debugInfo()['username'])->toBe('alice')
        ->and($bearer->tokenType)->toBe('Bearer')
        ->and($oauth->tokenType)->toBe('MAC');

    $policy = SourcePolicy::default()
        ->withServer('https://{region}.api.example')
        ->withServerVariables(['region' => 'eu'])
        ->withCredential('api_key', $apiKey)
        ->withHeaders(['X-Org' => 'core'])
        ->withHeader('X-Tenant', 'acme')
        ->withTimeoutSeconds(12.5)
        ->withStrictValidation(false)
        ->withExtension('custom', 'yes');

    expect($policy->server())->toBe('https://{region}.api.example')
        ->and($policy->serverVariables())->toBe(['region' => 'eu'])
        ->and($policy->credential('api_key'))->toBe($apiKey)
        ->and($policy->headers)->toBe(['X-Org' => 'core', 'X-Tenant' => 'acme'])
        ->and($policy->timeoutSeconds())->toBe(12.5)
        ->and($policy->strictValidation())->toBeFalse()
        ->and($policy->extension('custom'))->toBe('yes');
});

it('keeps WorkflowContext activeSourcePolicy transient and supports input/step/rootScope helpers', function (): void {
    $policy = SourcePolicy::default()->withCredential('api_key', Credential::apiKey('TOP-SECRET'));
    $context = (new WorkflowContext('def_1'))
        ->withInput('user', 'alice')
        ->withStepInputs('s1', ['q' => 'pets'])
        ->withActiveSourcePolicy($policy);

    expect($context->getActiveSourcePolicy())->toBe($policy)
        ->and($context->getInputs())->toBe(['user' => 'alice'])
        ->and($context->getSteps()['s1']['inputs'])->toBe(['q' => 'pets'])
        ->and($context->rootScope())->toBe([
            'inputs' => ['user' => 'alice'],
            'steps' => ['s1' => ['inputs' => ['q' => 'pets']]],
            'components' => [],
        ])
        ->and($context->toArray())->not->toHaveKey('activeSourcePolicy')
        ->and(json_encode($context->toArray()))->not->toContain('TOP-SECRET');
});
