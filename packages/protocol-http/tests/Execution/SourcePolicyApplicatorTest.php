<?php

declare(strict_types=1);

use Alama\Arazzo\Contracts\Policy\Credential;
use Alama\Arazzo\Contracts\Policy\SourcePolicy;
use Alama\Arazzo\Contracts\Spec\Enum\SourceType;
use Alama\Arazzo\Contracts\Spec\NormalizedOpenApiOperation;
use Alama\Arazzo\Contracts\Spec\ResolvedOperation;
use Alama\Arazzo\Contracts\Spec\SourceDescription;
use Alama\Arazzo\Protocol\Http\Execution\SourcePolicyApplicator;
use Alama\Arazzo\Protocol\Http\Resolver\OpenApiOperationHandle;
use cebe\openapi\spec\OpenApi;
use GuzzleHttp\Psr7\Request;

function applicatorHandle(array $openApiSpec, string $path = '/items', string $method = 'get'): OpenApiOperationHandle
{
    $openApi = new OpenApi($openApiSpec);
    $op = $openApi->paths[$path]->{$method};

    return new OpenApiOperationHandle(
        new ResolvedOperation(
            source: new SourceDescription('api', 'api.json', SourceType::Openapi),
            normalized: new NormalizedOpenApiOperation($path, $method, null, [], [], [], [], [], []),
            rpcProtocol: null,
            operationName: null,
            rpcMethod: null,
            graphqlOperation: null,
            interaction: null,
        ),
        $openApi,
        $op,
    );
}

it('resolves base URL from OpenAPI server defaults, policy serverVariables, and policy server override', function (): void {
    $applicator = new SourcePolicyApplicator();

    $openApiWithVars = new OpenApi([
        'openapi' => '3.0.3',
        'info' => ['title' => 'T', 'version' => '1'],
        'servers' => [[
            'url' => 'https://{env}.example.com/{region}/',
            'variables' => [
                'env' => ['default' => 'prod'],
                'region' => ['default' => 'us'],
            ],
        ]],
        'paths' => [],
    ]);

    expect($applicator->resolveBaseUrl($openApiWithVars, null))
        ->toBe('https://prod.example.com/us');

    expect($applicator->resolveBaseUrl($openApiWithVars, SourcePolicy::default()->withServerVariables(['env' => 'staging'])))
        ->toBe('https://staging.example.com/us');

    expect($applicator->resolveBaseUrl(
        $openApiWithVars,
        SourcePolicy::default()
            ->withServer('https://{tenant}.override.test/')
            ->withServerVariables(['tenant' => 'acme']),
    ))->toBe('https://acme.override.test');

    $noServers = new OpenApi(['openapi' => '3.0.3', 'info' => ['title' => 'T', 'version' => '1'], 'servers' => [], 'paths' => []]);
    expect($applicator->resolveBaseUrl($noServers, null))->toBe('');
});

it('applies static headers without clobbering step-level headers and passes through when policy is null', function (): void {
    $applicator = new SourcePolicyApplicator();
    $handle = applicatorHandle([
        'openapi' => '3.0.3',
        'info' => ['title' => 'T', 'version' => '1'],
        'paths' => ['/items' => ['get' => ['responses' => ['200' => ['description' => 'ok']]]]],
    ]);

    $initial = new Request('GET', 'https://api.test/items', ['X-Tenant' => 'step-override']);
    expect($applicator->applyToRequest($initial, $handle, null))->toBe($initial);

    $policy = SourcePolicy::default()->withHeaders([
        'X-Tenant' => 'default-tenant',
        'X-Client' => 'arazzo',
    ]);

    $result = $applicator->applyToRequest($initial, $handle, $policy);
    expect($result->getHeaderLine('X-Tenant'))->toBe('step-override')
        ->and($result->getHeaderLine('X-Client'))->toBe('arazzo');
});

it('injects credentials strictly when operation security matches and sends nothing on security: [] or omitted security', function (): void {
    $applicator = new SourcePolicyApplicator();

    $spec = [
        'openapi' => '3.0.3',
        'info' => ['title' => 'T', 'version' => '1'],
        'security' => [['header_key' => []]],
        'components' => [
            'securitySchemes' => [
                'header_key' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-API-Key'],
                'query_key' => ['type' => 'apiKey', 'in' => 'query', 'name' => 'api_key'],
                'cookie_key' => ['type' => 'apiKey', 'in' => 'cookie', 'name' => 'sid'],
                'weird_key' => ['type' => 'apiKey', 'in' => 'body', 'name' => 'ignored'],
                'bearer_auth' => ['type' => 'http', 'scheme' => 'bearer'],
                'basic_auth' => ['type' => 'http', 'scheme' => 'basic'],
                'oauth_auth' => ['type' => 'oauth2', 'flows' => []],
            ],
        ],
        'paths' => [
            '/inherited' => ['get' => ['responses' => ['200' => ['description' => 'ok']]]],
            '/public' => ['get' => ['security' => [], 'responses' => ['200' => ['description' => 'ok']]]],
            '/query' => ['get' => ['security' => [['query_key' => []]], 'responses' => ['200' => ['description' => 'ok']]]],
            '/cookie' => ['get' => ['security' => [['cookie_key' => []]], 'responses' => ['200' => ['description' => 'ok']]]],
            '/weird' => ['get' => ['security' => [['weird_key' => []]], 'responses' => ['200' => ['description' => 'ok']]]],
            '/bearer' => ['get' => ['security' => [['bearer_auth' => []]], 'responses' => ['200' => ['description' => 'ok']]]],
            '/basic' => ['get' => ['security' => [['basic_auth' => []]], 'responses' => ['200' => ['description' => 'ok']]]],
            '/oauth' => ['get' => ['security' => [['oauth_auth' => []]], 'responses' => ['200' => ['description' => 'ok']]]],
            '/combo-or' => ['get' => ['security' => [['missing' => [], 'header_key' => []], ['bearer_auth' => []]], 'responses' => ['200' => ['description' => 'ok']]]],
            '/unmatched' => ['get' => ['security' => [['unknown_scheme' => []]], 'responses' => ['200' => ['description' => 'ok']]]],
        ],
    ];

    $policy = SourcePolicy::default()
        ->withCredential('header_key', Credential::apiKey('hdr-secret'))
        ->withCredential('query_key', Credential::apiKey('q-secret'))
        ->withCredential('cookie_key', Credential::apiKey('c-secret'))
        ->withCredential('weird_key', Credential::apiKey('w-secret'))
        ->withCredential('bearer_auth', Credential::bearer('tok-123'))
        ->withCredential('basic_auth', Credential::basic('alice', 's3cr3t'))
        ->withCredential('oauth_auth', Credential::oauth2('oa-456', 'MAC'));

    // Inherited root security -> header apiKey
    $req = $applicator->applyToRequest(new Request('GET', 'https://api.test/inherited'), applicatorHandle($spec, '/inherited'), $policy);
    expect($req->getHeaderLine('X-API-Key'))->toBe('hdr-secret');

    // Explicit security: [] -> ZERO credentials sent
    $req = $applicator->applyToRequest(new Request('GET', 'https://api.test/public'), applicatorHandle($spec, '/public'), $policy);
    expect($req->hasHeader('X-API-Key'))->toBeFalse()
        ->and($req->hasHeader('Authorization'))->toBeFalse();

    // Query apiKey (empty query + appending to existing query)
    $req = $applicator->applyToRequest(new Request('GET', 'https://api.test/query'), applicatorHandle($spec, '/query'), $policy);
    expect((string) $req->getUri())->toBe('https://api.test/query?api_key=q-secret');
    $req2 = $applicator->applyToRequest(new Request('GET', 'https://api.test/query?foo=1'), applicatorHandle($spec, '/query'), $policy);
    expect((string) $req2->getUri())->toBe('https://api.test/query?foo=1&api_key=q-secret');

    // Cookie apiKey (empty cookie + appending to existing cookie)
    $req = $applicator->applyToRequest(new Request('GET', 'https://api.test/cookie'), applicatorHandle($spec, '/cookie'), $policy);
    expect($req->getHeaderLine('Cookie'))->toBe('sid=c-secret');
    $req2 = $applicator->applyToRequest(new Request('GET', 'https://api.test/cookie', ['Cookie' => 'a=1']), applicatorHandle($spec, '/cookie'), $policy);
    expect($req2->getHeaderLine('Cookie'))->toBe('a=1; sid=c-secret');

    // Unknown apiKey location is safely ignored
    $req = $applicator->applyToRequest(new Request('GET', 'https://api.test/weird'), applicatorHandle($spec, '/weird'), $policy);
    expect($req->getHeaders())->not->toHaveKey('ignored');

    // Bearer
    $req = $applicator->applyToRequest(new Request('GET', 'https://api.test/bearer'), applicatorHandle($spec, '/bearer'), $policy);
    expect($req->getHeaderLine('Authorization'))->toBe('Bearer tok-123');

    // Basic
    $req = $applicator->applyToRequest(new Request('GET', 'https://api.test/basic'), applicatorHandle($spec, '/basic'), $policy);
    expect($req->getHeaderLine('Authorization'))->toBe('Basic '.base64_encode('alice:s3cr3t'));

    // OAuth2 custom token type
    $req = $applicator->applyToRequest(new Request('GET', 'https://api.test/oauth'), applicatorHandle($spec, '/oauth'), $policy);
    expect($req->getHeaderLine('Authorization'))->toBe('MAC oa-456');

    // OR fallback when first AND alternative lacks a credential
    $req = $applicator->applyToRequest(new Request('GET', 'https://api.test/combo-or'), applicatorHandle($spec, '/combo-or'), $policy);
    expect($req->getHeaderLine('Authorization'))->toBe('Bearer tok-123')
        ->and($req->hasHeader('X-API-Key'))->toBeFalse();

    // Unmatched scheme leaves request untouched
    $req = $applicator->applyToRequest(new Request('GET', 'https://api.test/unmatched'), applicatorHandle($spec, '/unmatched'), $policy);
    expect($req->hasHeader('X-API-Key'))->toBeFalse()
        ->and($req->hasHeader('Authorization'))->toBeFalse();
});
