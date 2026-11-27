<?php

declare(strict_types=1);

namespace Alama\Arazzo\Tests\Protocol\Http\Execution;

use Alama\Arazzo\Contracts\Spec\Enum\SourceType;
use Alama\Arazzo\Contracts\Spec\NormalizedOpenApiOperation;
use Alama\Arazzo\Contracts\Spec\OpenApiPayload;
use Alama\Arazzo\Contracts\Spec\ResolvedOperation;
use Alama\Arazzo\Contracts\Spec\SourceDescription;
use Alama\Arazzo\Contracts\Spec\SourceDocument;
use Alama\Arazzo\Protocol\Http\Execution\DefaultOpenApiExecutor;
use Alama\Arazzo\Protocol\Http\Resolver\OpenApiOperationHandle;
use Alama\Arazzo\Sources\Resolver\Interfaces\SourceResolver;
use cebe\openapi\Reader;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Mockery;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

it('builds and sends an openapi request using the schema to route parameters', function () {
    $openapiJson = <<<'JSON'
    {
      "openapi": "3.0.0",
      "info": { "title": "API", "version": "1.0" },
      "servers": [{ "url": "https://api.example.com/v1" }],
      "paths": {
        "/users/{userId}": {
          "get": {
            "operationId": "getUser",
            "parameters": [
              { "name": "userId", "in": "path", "required": true, "schema": { "type": "integer" } },
              { "name": "limit", "in": "query", "schema": { "type": "integer" } },
              { "name": "X-Auth", "in": "header", "schema": { "type": "string" } }
            ]
          }
        }
      }
    }
    JSON;

    $openApi = Reader::readFromJson($openapiJson);

    $sourceDoc = new SourceDocument('test', SourceType::Openapi, 'http://test', json_decode($openapiJson, true));
    $sourceResolver = Mockery::mock(SourceResolver::class);
    $sourceResolver->shouldReceive('resolve')->andReturn($sourceDoc);

    $httpClient = Mockery::mock(ClientInterface::class);
    $httpClient->shouldReceive('sendRequest')->withArgs(function ($request) {
        expect($request->getMethod())->toBe('GET');
        expect((string) $request->getUri())->toBe('https://api.example.com/v1/users/42?limit=10');
        expect($request->getHeaderLine('X-Auth'))->toBe('token123');

        return true;
    })->andReturn(new Response(200));

    $requestFactory = Mockery::mock(RequestFactoryInterface::class);
    $requestFactory->shouldReceive('createRequest')->andReturnUsing(function ($method, $uri) {
        return new Request($method, $uri);
    });

    $executor = new DefaultOpenApiExecutor(
        $httpClient,
        $requestFactory,
        new NullLogger(),
    );

    $payload = new OpenApiPayload(
        auto: ['userId' => '42', 'limit' => '10', 'X-Auth' => 'token123'],
    );

    $source = new SourceDescription('test', 'test.json', SourceType::Openapi);
    $normalized = new NormalizedOpenApiOperation(
        path: '/users/{userId}',
        method: 'get',
        resolvedServerUrl: 'https://api.example.com/v1',
        pathParameters: [
            'userId' => ['name' => 'userId', 'in' => 'path', 'schema' => ['type' => 'integer']],
        ],
        queryParameters: [
            'limit' => ['name' => 'limit', 'in' => 'query', 'schema' => ['type' => 'integer']],
        ],
        headerParameters: [
            'X-Auth' => ['name' => 'X-Auth', 'in' => 'header', 'schema' => ['type' => 'string']],
        ],
    );

    $resolved = new OpenApiOperationHandle(
        new ResolvedOperation(
            source: $source,
            normalized: $normalized,
            rpcProtocol: null,
            operationName: null,
            rpcMethod: null,
            graphqlOperation: null,
            interaction: null,
        ),
        $openApi,
        clone $openApi->paths->getPath('/users/{userId}')->get,
    );

    $response = $executor->execute($resolved, $payload);

    expect($response->getStatusCode())->toBe(200);
});

it('logs and falls back to the raw value when a parameter cannot be cast to its schema type', function () {
    $openapiJson = <<<'JSON'
    {
      "openapi": "3.0.0",
      "info": { "title": "API", "version": "1.0" },
      "servers": [{ "url": "https://api.example.com/v1" }],
      "paths": {
        "/search": {
          "get": {
            "operationId": "search",
            "parameters": [
              { "name": "limit", "in": "query", "schema": { "type": "integer" } }
            ]
          }
        }
      }
    }
    JSON;

    $openApi = Reader::readFromJson($openapiJson);

    $httpClient = Mockery::mock(ClientInterface::class);
    $httpClient->shouldReceive('sendRequest')->withArgs(function ($request) {
        // The uncastable value is forwarded as-is rather than dropped.
        expect((string) $request->getUri())->toBe('https://api.example.com/v1/search?limit=not-a-number');

        return true;
    })->andReturn(new Response(200));

    $requestFactory = Mockery::mock(RequestFactoryInterface::class);
    $requestFactory->shouldReceive('createRequest')->andReturnUsing(function ($method, $uri) {
        return new Request($method, $uri);
    });

    $logger = Mockery::mock(LoggerInterface::class);
    $logger->shouldReceive('warning')->once()->with(Mockery::pattern("/^Failed to cast value to schema type 'integer'/"));
    $logger->shouldReceive('debug')->zeroOrMoreTimes();
    $executor = new DefaultOpenApiExecutor($httpClient, $requestFactory, $logger);

    $payload = new OpenApiPayload(auto: ['limit' => 'not-a-number']);

    $source = new SourceDescription('test', 'test.json', SourceType::Openapi);
    $normalized = new NormalizedOpenApiOperation(
        path: '/search',
        method: 'get',
        resolvedServerUrl: 'https://api.example.com/v1',
        queryParameters: [
            'limit' => ['name' => 'limit', 'in' => 'query', 'schema' => ['type' => 'integer']],
        ],
    );

    $resolved = new OpenApiOperationHandle(
        new ResolvedOperation(
            source: $source,
            normalized: $normalized,
            rpcProtocol: null,
            operationName: null,
            rpcMethod: null,
            graphqlOperation: null,
            interaction: null,
        ),
        $openApi,
        clone $openApi->paths->getPath('/search')->get,
    );

    expect($executor->execute($resolved, $payload)->getStatusCode())->toBe(200);
});

it('compiles an indexed expression into the request path', function () {
    $openapiJson = <<<'JSON'
    {
      "openapi": "3.0.0",
      "info": { "title": "API", "version": "1.0" },
      "servers": [{ "url": "https://api.example.com" }],
      "paths": {
        "/v3/pet/{petId}": {
          "get": {
            "operationId": "getPetById",
            "parameters": [
              { "name": "petId", "in": "path", "required": true, "schema": { "type": "integer" } }
            ]
          }
        }
      }
    }
    JSON;

    $openApi = Reader::readFromJson($openapiJson);

    $httpClient = Mockery::mock(ClientInterface::class);
    $httpClient->shouldReceive('sendRequest')->withArgs(function ($request) {
        expect($request->getMethod())->toBe('GET');
        expect((string) $request->getUri())->toBe('https://api.example.com/v3/pet/1');

        return true;
    })->andReturn(new Response(200));

    $requestFactory = Mockery::mock(RequestFactoryInterface::class);
    $requestFactory->shouldReceive('createRequest')->andReturnUsing(function ($method, $uri) {
        return new Request($method, $uri);
    });

    $executor = new DefaultOpenApiExecutor($httpClient, $requestFactory, new NullLogger());

    $payload = new OpenApiPayload(auto: ['petId' => '1']);

    $source = new SourceDescription('test', 'test.json', SourceType::Openapi);
    $normalized = new NormalizedOpenApiOperation(
        path: '/v3/pet/{petId}',
        method: 'get',
        resolvedServerUrl: 'https://api.example.com',
        pathParameters: [
            'petId' => ['name' => 'petId', 'in' => 'path', 'schema' => ['type' => 'integer']],
        ],
    );

    $resolved = new OpenApiOperationHandle(
        new ResolvedOperation(
            source: $source,
            normalized: $normalized,
            rpcProtocol: null,
            operationName: null,
            rpcMethod: null,
            graphqlOperation: null,
            interaction: null,
        ),
        $openApi,
        clone $openApi->paths->getPath('/v3/pet/{petId}')->get,
    );

    expect($executor->execute($resolved, $payload)->getStatusCode())->toBe(200);
});

it('handles cookies, request body, scalar/form body, schema casting types, and Guzzle per-request timeout', function () {
    $openapiJson = <<<'JSON'
    {
      "openapi": "3.0.0",
      "info": { "title": "API", "version": "1.0" },
      "servers": [{ "url": "https://api.example.com" }],
      "paths": {
        "/items": {
          "post": {
            "operationId": "createItem",
            "parameters": [
              { "name": "sid", "in": "cookie", "schema": { "type": "string" } },
              { "name": "ratio", "in": "query", "schema": { "type": "number" } },
              { "name": "active", "in": "query", "schema": { "type": "boolean" } },
              { "name": "tags", "in": "query", "schema": { "type": "array" } },
              { "name": "extra", "in": "query", "schema": { "type": "object" } }
            ]
          }
        }
      }
    }
    JSON;

    $openApi = Reader::readFromJson($openapiJson);
    $source = new SourceDescription('test', 'test.json', SourceType::Openapi);
    $normalized = new NormalizedOpenApiOperation(
        path: '/items',
        method: 'post',
        resolvedServerUrl: 'https://api.example.com',
        queryParameters: [
            'ratio' => ['name' => 'ratio', 'in' => 'query', 'schema' => ['type' => 'number']],
            'active' => ['name' => 'active', 'in' => 'query', 'schema' => ['type' => 'boolean']],
            'tags' => ['name' => 'tags', 'in' => 'query', 'schema' => ['type' => 'array']],
            'extra' => ['name' => 'extra', 'in' => 'query', 'schema' => ['type' => 'object']],
        ],
        cookieParameters: [
            'sid' => ['name' => 'sid', 'in' => 'cookie', 'schema' => ['type' => 'string']],
        ],
    );

    $resolved = new OpenApiOperationHandle(
        new ResolvedOperation($source, $normalized, null, null, null, null, null),
        $openApi,
        clone $openApi->paths->getPath('/items')->post,
    );

    $capturedOptions = null;
    $guzzle = Mockery::mock(Client::class);
    $guzzle->shouldReceive('request')->once()->withArgs(function ($method, $uri, $options) use (&$capturedOptions) {
        $capturedOptions = ['method' => $method, 'uri' => $uri, 'options' => $options];

        return true;
    })->andReturn(new Response(201));

    $executor = new DefaultOpenApiExecutor($guzzle, new HttpFactory());

    $payload = new OpenApiPayload(
        auto: ['sid' => 'abc', 'ratio' => '1.5', 'active' => 'true', 'tags' => ['a'], 'extra' => 'raw', 'untyped' => 'keep'],
        body: ['name' => 'widget'],
        bodyMediaType: 'application/json',
    );

    $response = $executor->execute($resolved, $payload, timeoutSeconds: 3.5);

    expect($response->getStatusCode())->toBe(201)
        ->and($capturedOptions['method'])->toBe('POST')
        ->and($capturedOptions['options']['timeout'])->toBe(3.5)
        ->and($capturedOptions['options']['body'])->toBe('{"name":"widget"}')
        ->and($capturedOptions['options']['headers']['Cookie'])->toBe(['sid=abc']);

    // Non-JSON form body path via PSR-18 client
    $capturedFormBody = null;
    $psr18 = Mockery::mock(ClientInterface::class);
    $psr18->shouldReceive('sendRequest')->once()->withArgs(function ($req) use (&$capturedFormBody) {
        $capturedFormBody = (string) $req->getBody();

        return true;
    })->andReturn(new Response(200));

    $formExecutor = new DefaultOpenApiExecutor($psr18, new HttpFactory());
    $formExecutor->execute($resolved, new OpenApiPayload(body: ['a' => '1'], bodyMediaType: 'application/x-www-form-urlencoded'));

    expect($capturedFormBody)->toBe('a=1');
});
