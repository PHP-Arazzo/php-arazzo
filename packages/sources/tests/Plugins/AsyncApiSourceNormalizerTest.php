<?php

declare(strict_types=1);

namespace Alama\Arazzo\Tests\Sources\Plugins;

use Alama\Arazzo\Contracts\Spec\Enum\SourceType;
use Alama\Arazzo\Contracts\Spec\SourceDescription;
use Alama\Arazzo\Sources\Exceptions\InvalidAsyncApiSourceException;
use Alama\Arazzo\Sources\Plugins\SourceNormalizer\AsyncApiSourceNormalizer;
use Alama\Arazzo\Sources\Registries\SourceNormalizerRegistry;
use Symfony\Component\Yaml\Yaml;

it('supports asyncapi source type and identifies as asyncapi', function (): void {
    $normalizer = new AsyncApiSourceNormalizer();

    expect($normalizer->name())->toBe('asyncapi')
        ->and($normalizer->supports(SourceType::Asyncapi))->toBeTrue()
        ->and($normalizer->supports(SourceType::Openapi))->toBeFalse();
});

it('is registered in SourceNormalizerRegistry for SourceType::Asyncapi', function (): void {
    SourceNormalizerRegistry::reset();
    $normalizer = SourceNormalizerRegistry::create()->get(SourceType::Asyncapi);

    expect($normalizer)->not->toBeNull()
        ->and($normalizer)->toBeInstanceOf(AsyncApiSourceNormalizer::class);
});

it('normalizes an AsyncAPI 3.0 document with channels, addresses, and operations', function (): void {
    $yaml = <<<'YAML'
asyncapi: 3.0.0
info:
  title: Users Service
  version: 1.0.0
servers:
  production:
    host: broker.example.com:8443
    pathname: /api/v1
    protocol: https
channels:
  userEvents:
    address: users/signup
    messages:
      userSignedUp:
        payload:
          type: object
operations:
  onUserSignedUp:
    action: receive
    channel:
      $ref: '#/channels/userEvents'
YAML;

    $source = new SourceDescription('usersService', 'https://specs.example.com/asyncapi.yaml', SourceType::Asyncapi);
    $normalizer = new AsyncApiSourceNormalizer();
    $index = $normalizer->normalize($source, json_encode(Yaml::parse($yaml) ?: []));

    expect($index)->toHaveKey('#/channels/userEvents')
        ->and($index)->toHaveKey('channels/userEvents')
        ->and($index)->toHaveKey('userEvents')
        ->and($index)->toHaveKey('$sourceDescriptions.usersService.userEvents')
        ->and($index)->toHaveKey('users/signup')
        ->and($index)->toHaveKey('onUserSignedUp')
        ->and($index)->toHaveKey('$sourceDescriptions.usersService.onUserSignedUp');

    $resolved = $index['#/channels/userEvents'];
    expect($resolved->source)->toBe($source)
        ->and($resolved->normalized->path)->toBe('users/signup')
        ->and($resolved->normalized->resolvedServerUrl)->toBe('https://broker.example.com:8443/api/v1')
        ->and($resolved->operationName)->toBe('userEvents')
        ->and($resolved->binding())->toBe('asyncapi');
});

it('normalizes an AsyncAPI 2.x document with path channels and inline operations', function (): void {
    $yaml = <<<'YAML'
asyncapi: 2.6.0
info:
  title: Streetlights API
  version: 1.0.0
servers:
  mqttServer:
    url: mqtt://broker.example.com:1883
    protocol: mqtt
  httpBridge:
    url: https://bridge.example.com/v2
    protocol: https
channels:
  streetlights/events/measured:
    servers:
      - httpBridge
    subscribe:
      operationId: onLightingMeasured
YAML;

    $source = new SourceDescription('streetlights', 'https://specs.example.com/asyncapi.yaml', SourceType::Asyncapi);
    $normalizer = new AsyncApiSourceNormalizer();
    $index = $normalizer->normalize($source, json_encode(Yaml::parse($yaml) ?: []));

    expect($index)->toHaveKey('#/channels/streetlights/events/measured')
        ->and($index)->toHaveKey('streetlights/events/measured')
        ->and($index)->toHaveKey('onLightingMeasured')
        ->and($index)->toHaveKey('$sourceDescriptions.streetlights.onLightingMeasured');

    $resolved = $index['onLightingMeasured'];
    expect($resolved->normalized->path)->toBe('streetlights/events/measured')
        ->and($resolved->normalized->resolvedServerUrl)->toBe('https://bridge.example.com/v2');
});

it('falls back through server chain preferring http/https', function (): void {
    $yaml = <<<'YAML'
asyncapi: 3.0.0
info:
  title: Multi Server API
  version: 1.0.0
servers:
  kafka:
    host: kafka.example.com:9092
    protocol: kafka
  webhook:
    host: webhooks.example.com
    protocol: https
channels:
  notifications:
    address: push/notifications
YAML;

    $source = new SourceDescription('events', 'https://specs.example.com/asyncapi.yaml', SourceType::Asyncapi);
    $normalizer = new AsyncApiSourceNormalizer();
    $index = $normalizer->normalize($source, json_encode(Yaml::parse($yaml) ?: []));

    expect($index['notifications']->normalized->resolvedServerUrl)->toBe('https://webhooks.example.com');
});

it('throws InvalidAsyncApiSourceException on invalid json or empty channels', function (): void {
    $source = new SourceDescription('bad', 'https://specs.example.com/bad.yaml', SourceType::Asyncapi);
    $normalizer = new AsyncApiSourceNormalizer();

    expect($normalizer->priority())->toBe(0);

    expect(fn () => $normalizer->normalize($source, 'invalid json {'))
        ->toThrow(InvalidAsyncApiSourceException::class);

    expect(fn () => $normalizer->normalize($source, "bad: [[\n  - invalid: {"))
        ->toThrow(InvalidAsyncApiSourceException::class);

    expect(fn () => $normalizer->normalize($source, json_encode(['asyncapi' => '3.0.0'])))
        ->toThrow(InvalidAsyncApiSourceException::class);
});

it('covers AsyncAPI 3.0 server resolution variants, variable substitution, and fallbacks', function (): void {
    $yaml = <<<'YAML'
asyncapi: 3.0.0
info:
  title: Edge Cases API
  version: 1.0.0
servers:
  dev:
    host: https://api.dev.example.com/{tenant}
    pathname: /{version}/events
    variables:
      tenant:
        default: acme
      version:
        default: v1
  kafka:
    host: kafka://broker.example.com:9092
    protocol: kafka
channels:
  channelWithoutAddress:
    servers:
      - dev
  channelWithRefServer:
    address: custom/address
    servers:
      - $ref: '#/servers/dev'
  channelFallbackToNonHttp:
    servers:
      - kafka
  channelNoServers:
    address: standalone/channel
operations:
  invalidOp: "not-an-array"
  validOp:
    action: send
    channel:
      $ref: '#/channels/channelWithoutAddress'
YAML;

    $source = new SourceDescription('edge30', 'https://specs.example.com/edge.yaml', SourceType::Asyncapi);
    $normalizer = new AsyncApiSourceNormalizer();
    $index = $normalizer->normalize($source, json_encode(Yaml::parse($yaml) ?: []));

    expect($index['channelWithoutAddress']->normalized->path)->toBe('channelWithoutAddress')
        ->and($index['channelWithoutAddress']->normalized->resolvedServerUrl)->toBe('https://api.dev.example.com/acme/v1/events')
        ->and($index['channelWithRefServer']->normalized->path)->toBe('custom/address')
        ->and($index['channelWithRefServer']->normalized->resolvedServerUrl)->toBe('https://api.dev.example.com/acme/v1/events')
        ->and($index['validOp']->normalized->path)->toBe('channelWithoutAddress');

    // Test fallback when servers are empty or have invalid server entries
    $docNoServers = [
        'asyncapi' => '',
        'servers' => [
            'badServer' => 'not-an-array',
        ],
        'channels' => [
            'rawChannel' => ['address' => 'raw/path'],
            'badChannel' => 'not-an-array',
        ],
    ];
    $indexNoServers = $normalizer->normalize($source, json_encode($docNoServers));
    expect($indexNoServers['rawChannel']->normalized->resolvedServerUrl)->toBeNull();
});

it('covers AsyncAPI 2.x server resolution variants, variable substitution, and fallbacks', function (): void {
    $yaml = <<<'YAML'
asyncapi: 2.4.0
info:
  title: Edge Cases 2.x API
  version: 1.0.0
servers:
  kafka:
    url: kafka://broker.example.com:9092
    protocol: kafka
  web:
    url: http://{host}:{port}/hub
    protocol: http
    variables:
      host:
        default: bridge.example.com
      port:
        default: '8080'
channels:
  device/measurements:
    servers:
      - missingServer
    subscribe:
      operationId: onMeasurement
  device/alerts:
    publish:
      operationId: sendAlert
  badChannel: "not-an-array"
YAML;

    $source = new SourceDescription('edge20', 'https://specs.example.com/edge20.yaml', SourceType::Asyncapi);
    $normalizer = new AsyncApiSourceNormalizer();
    $index = $normalizer->normalize($source, json_encode(Yaml::parse($yaml) ?: []));

    expect($index['onMeasurement']->normalized->resolvedServerUrl)->toBe('http://bridge.example.com:8080/hub')
        ->and($index['sendAlert']->normalized->resolvedServerUrl)->toBe('http://bridge.example.com:8080/hub');

    // Test AsyncAPI 2.x fallback to first server when no HTTP server exists (stripping non-http scheme)
    $yamlNonHttp = <<<'YAML'
asyncapi: 2.0.0
info:
  title: Non HTTP 2.x API
  version: 1.0.0
servers:
  kafka:
    url: mqtt://broker.example.com:9092
    protocol: mqtt
channels:
  telemetry:
    publish:
      operationId: postTelemetry
YAML;
    $indexNonHttp = $normalizer->normalize($source, json_encode(Yaml::parse($yamlNonHttp) ?: []));
    expect($indexNonHttp['postTelemetry']->normalized->resolvedServerUrl)->toBe('https://broker.example.com:9092');

    // Test AsyncAPI 2.x with invalid servers entry falling through to null
    $docNoServers = [
        'asyncapi' => '2.0.0',
        'servers' => [
            'bad' => 'not-an-array',
        ],
        'channels' => [
            'metrics' => [
                'publish' => ['operationId' => 'postMetrics'],
            ],
        ],
    ];
    $index20NoServers = $normalizer->normalize($source, json_encode($docNoServers));
    expect($index20NoServers['postMetrics']->normalized->resolvedServerUrl)->toBeNull();

    // Test AsyncAPI 2.x with empty servers array
    $docEmptyServers = [
        'asyncapi' => '2.0.0',
        'servers' => [],
        'channels' => [
            'metrics' => [
                'publish' => ['operationId' => 'postMetrics'],
            ],
        ],
    ];
    $indexEmptyServers = $normalizer->normalize($source, json_encode($docEmptyServers));
    expect($indexEmptyServers['postMetrics']->normalized->resolvedServerUrl)->toBeNull();
});
