<?php

declare(strict_types=1);

namespace Alama\Arazzo\Tests\Parser\Decoders;

use Alama\Arazzo\Document\Parser\DecodePolicy;
use Alama\Arazzo\Document\Parser\Decoders\SymfonyYamlDecoder;
use Alama\Arazzo\Document\Parser\Exceptions\CustomTagsForbiddenException;
use Alama\Arazzo\Document\Parser\Exceptions\DecodeException;
use Alama\Arazzo\Document\Parser\Exceptions\DecodeLimitException;
use Alama\Arazzo\Document\Parser\Exceptions\MaxAliasesExceededException;
use Alama\Arazzo\Document\Parser\Exceptions\MaxBytesExceededException;
use Alama\Arazzo\Document\Parser\Exceptions\MaxDepthExceededException;
use Alama\Arazzo\Document\Parser\Exceptions\MaxNodesExceededException;
use Alama\Arazzo\Document\Parser\Exceptions\ObjectDeserializationForbiddenException;
use Alama\Arazzo\Document\Parser\YamlPolicy;

it('rejects documents over max bytes before decoding', function (): void {
    $policy = DecodePolicy::default()->withMaxBytes(100);
    $decoder = new SymfonyYamlDecoder($policy);

    $oversizedContent = str_repeat('# large comment'."\n", 20); // > 100 bytes

    expect(strlen($oversizedContent))->toBeGreaterThan(100);

    expect(fn () => $decoder->decode($oversizedContent))
        ->toThrow(function (MaxBytesExceededException $e): void {
            expect($e)->toBeInstanceOf(DecodeException::class)
                ->and($e)->toBeInstanceOf(DecodeLimitException::class)
                ->and($e->limit)->toBe('maxBytes')
                ->and($e->codeId)->toBe('document.yaml_limit')
                ->and($e->getMessage())->toContain('maxBytes')
                ->and($e->getMessage())->toContain('document.yaml_limit');
        });
});

it('rejects documents exceeding maxAliases', function (): void {
    $policy = DecodePolicy::default()->withYamlPolicy(
        YamlPolicy::default()->withMaxAliases(2),
    );
    $decoder = new SymfonyYamlDecoder($policy);

    $yaml = <<<'YAML'
default_item: &item
  name: item
list:
  - *item
  - *item
  - *item
YAML;

    expect(fn () => $decoder->decode($yaml))
        ->toThrow(function (MaxAliasesExceededException $e): void {
            expect($e)->toBeInstanceOf(DecodeException::class)
                ->and($e)->toBeInstanceOf(DecodeLimitException::class)
                ->and($e->limit)->toBe('maxAliases')
                ->and($e->codeId)->toBe('document.yaml_limit')
                ->and($e->getMessage())->toContain('maxAliases')
                ->and($e->getMessage())->toContain('document.yaml_limit');
        });
});

it('rejects alias bomb before parsing', function (): void {
    $policy = DecodePolicy::default()->withYamlPolicy(
        YamlPolicy::default()->withMaxAliases(10),
    );
    $decoder = new SymfonyYamlDecoder($policy);

    // Billion laughs: raw aliases count is 8, but expanded fan-out is 2^4 = 16 > 10
    $yaml = <<<'YAML'
a: &a ["lol", "lol"]
b: &b [*a, *a]
c: &c [*b, *b]
d: &d [*c, *c]
e: [*d, *d]
YAML;

    expect(fn () => $decoder->decode($yaml))
        ->toThrow(function (MaxAliasesExceededException $e): void {
            expect($e)->toBeInstanceOf(DecodeException::class)
                ->and($e)->toBeInstanceOf(DecodeLimitException::class)
                ->and($e->limit)->toBe('maxAliases')
                ->and($e->codeId)->toBe('document.yaml_limit')
                ->and($e->getMessage())->toContain('maxAliases');
        });
});

it('rejects depth over max', function (): void {
    $policy = DecodePolicy::default()->withMaxDepth(3);
    $decoder = new SymfonyYamlDecoder($policy);

    // Nesting depth 5 > 3
    $yaml = "a:\n  b:\n    c:\n      d:\n        e: 1\n";

    expect(fn () => $decoder->decode($yaml))
        ->toThrow(function (MaxDepthExceededException $e): void {
            expect($e)->toBeInstanceOf(DecodeException::class)
                ->and($e)->toBeInstanceOf(DecodeLimitException::class)
                ->and($e->limit)->toBe('maxDepth')
                ->and($e->codeId)->toBe('document.yaml_limit')
                ->and($e->getMessage())->toContain('maxDepth')
                ->and($e->getMessage())->toContain('document.yaml_limit');
        });
});

it('rejects nodes over max', function (): void {
    $policy = DecodePolicy::default()->withMaxNodes(3);
    $decoder = new SymfonyYamlDecoder($policy);

    // 4 items > 3
    $yaml = "items:\n  - 1\n  - 2\n  - 3\n  - 4\n";

    expect(fn () => $decoder->decode($yaml))
        ->toThrow(function (MaxNodesExceededException $e): void {
            expect($e)->toBeInstanceOf(DecodeException::class)
                ->and($e)->toBeInstanceOf(DecodeLimitException::class)
                ->and($e->limit)->toBe('maxNodes')
                ->and($e->codeId)->toBe('document.yaml_limit')
                ->and($e->getMessage())->toContain('maxNodes')
                ->and($e->getMessage())->toContain('document.yaml_limit');
        });
});

it('refuses custom tags when not allowed', function (): void {
    $policy = DecodePolicy::default(); // allowCustomTags: false by default
    $decoder = new SymfonyYamlDecoder($policy);

    $yaml = "tag: !my-custom-tag value\n";

    expect(fn () => $decoder->decode($yaml))
        ->toThrow(function (CustomTagsForbiddenException $e): void {
            expect($e)->toBeInstanceOf(DecodeException::class)
                ->and($e)->toBeInstanceOf(DecodeLimitException::class)
                ->and($e->limit)->toBe('allowCustomTags')
                ->and($e->codeId)->toBe('document.yaml_limit')
                ->and($e->getMessage())->toContain('allowCustomTags')
                ->and($e->getMessage())->toContain('document.yaml_limit');
        });
});

it('refuses php object deserialization', function (): void {
    $policy = DecodePolicy::default(); // allowObjectDeserialization: false by default
    $decoder = new SymfonyYamlDecoder($policy);

    $yaml = "obj: !php/object:O:8:\"stdClass\":0:{}\n";

    expect(fn () => $decoder->decode($yaml))
        ->toThrow(function (ObjectDeserializationForbiddenException $e): void {
            expect($e)->toBeInstanceOf(DecodeException::class)
                ->and($e)->toBeInstanceOf(DecodeLimitException::class)
                ->and($e->limit)->toBe('allowObjectDeserialization')
                ->and($e->codeId)->toBe('document.yaml_limit')
                ->and($e->getMessage())->toContain('allowObjectDeserialization')
                ->and($e->getMessage())->toContain('document.yaml_limit');
        });
});

it('decodes legitimate fixtures unchanged', function (): void {
    $decoder = new SymfonyYamlDecoder(DecodePolicy::default());

    $arazzoFixture = (string) file_get_contents(__DIR__.'/../../../../../LoginAndRetrievePets.arazzo.yaml');
    $openapiFixture = (string) file_get_contents(__DIR__.'/../../../../../petstore-openapi.yaml');

    $decodedArazzo = $decoder->decode($arazzoFixture);
    $decodedOpenApi = $decoder->decode($openapiFixture);

    expect($decodedArazzo)->toBeArray()
        ->and($decodedArazzo['arazzo'] ?? null)->toBe('1.0.0')
        ->and($decodedOpenApi)->toBeArray()
        ->and($decodedOpenApi['openapi'] ?? null)->toBe('3.0.4');
});

it('is deterministic and idempotent on repeated decodes', function (): void {
    $decoder = new SymfonyYamlDecoder(DecodePolicy::default());

    $arazzoFixture = (string) file_get_contents(__DIR__.'/../../../../../LoginAndRetrievePets.arazzo.yaml');

    $first = $decoder->decode($arazzoFixture);
    $second = $decoder->decode($arazzoFixture);

    expect($first)->toBe($second);
});

it('rejects deserialized objects when allowCustomTags is true but allowObjectDeserialization is false', function (): void {
    $policy = DecodePolicy::default()->withYamlPolicy(
        YamlPolicy::default()
            ->withAllowCustomTags(true)
            ->withAllowObjectDeserialization(false),
    );
    $decoder = new SymfonyYamlDecoder($policy);

    expect(fn () => $decoder->decode('!custom [1, 2]'))
        ->toThrow(ObjectDeserializationForbiddenException::class);
});

it('allows standard YAML type tags like !!str and !!binary', function (): void {
    $decoder = new SymfonyYamlDecoder();
    $data = $decoder->decode("name: !!str hello\ndata: !!binary SGVsbG8=\n");

    expect($data)->toBe(['name' => 'hello', 'data' => 'Hello']);
});

it('allows object deserialization when policy allows it', function (): void {
    $policy = DecodePolicy::default()->withYamlPolicy(
        YamlPolicy::default()->withAllowObjectDeserialization(true),
    );
    $decoder = new SymfonyYamlDecoder($policy);
    $data = $decoder->decode("a: 1\n");

    expect($data)->toBeObject();
});

it('does not bomb when alias exists without anchors', function (): void {
    $decoder = new SymfonyYamlDecoder();

    expect(fn () => $decoder->decode('a: *missingAnchor'))
        ->toThrow(DecodeException::class);
});

it('rejects cyclic anchor definitions', function (): void {
    $policy = DecodePolicy::default()->withYamlPolicy(
        YamlPolicy::default()->withMaxAliases(5),
    );
    $decoder = new SymfonyYamlDecoder($policy);
    $yaml = "a: &a [*b]\nb: &b [*a]\n";

    expect(fn () => $decoder->decode($yaml))
        ->toThrow(MaxAliasesExceededException::class);
});

it('rejects top-level alias references when cumulative expansion exceeds maxAliases', function (): void {
    $policy = DecodePolicy::default()->withYamlPolicy(
        YamlPolicy::default()->withMaxAliases(3),
    );
    $decoder = new SymfonyYamlDecoder($policy);
    $yaml = "base: &b [1]\nitem: &a [*b]\nroots: [*a, *a]\n";

    expect(fn () => $decoder->decode($yaml))
        ->toThrow(MaxAliasesExceededException::class);
});
