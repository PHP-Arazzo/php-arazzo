<?php

declare(strict_types=1);

namespace Alama\Arazzo\Tests\Loader;

use Alama\Arazzo\Contracts\Spec\Enum\Format;
use Alama\Arazzo\Document\Parser\DecodePolicy;
use Alama\Arazzo\Document\Parser\Exceptions\LoaderException;
use Alama\Arazzo\Document\Parser\Loader;

function makeLoader(): Loader
{
    return Loader::defineDecodePolicy();
}

it('loads a yaml file', function (): void {
    $raw = makeLoader()->load(__DIR__.'/../fixtures/loader/minimal.yaml');

    expect($raw->format)->toBe(Format::Yaml)
        ->and($raw->data['arazzo'] ?? null)->toBe('1.0.0')
        ->and($raw->data['workflows'][0]['workflowId'] ?? null)->toBe('wf');
});

it('loads a json file', function (): void {
    $raw = makeLoader()->load(__DIR__.'/../fixtures/loader/minimal.json');

    expect($raw->format)->toBe(Format::Json)
        ->and($raw->data['arazzo'] ?? null)->toBe('1.0.0');
});

it('throws when file missing', function (): void {
    makeLoader()->load('/does/not/exist.yaml');
})->throws(LoaderException::class, 'not found');

it('throws on unsupported extension', function (): void {
    $tmp = tempnam(sys_get_temp_dir(), 'arz').'.txt';
    file_put_contents($tmp, 'x');
    try {
        makeLoader()->load($tmp);
    } finally {
        @unlink($tmp);
    }
})->throws(LoaderException::class, 'Unsupported');

it('throws on decode failure', function (): void {
    makeLoader()->load(__DIR__.'/../fixtures/loader/broken.yaml');
})->throws(LoaderException::class, 'decode');

it('throws when root is not an object', function (): void {
    makeLoader()->load(__DIR__.'/../fixtures/loader/not-object.yaml');
})->throws(LoaderException::class, 'Root');

it('applies decode policy defined via defineDecodePolicy to load', function (): void {
    $loader = Loader::defineDecodePolicy(DecodePolicy::default()->withMaxBytes(50));

    expect(fn () => $loader->load(__DIR__.'/../fixtures/loader/minimal.yaml'))
        ->toThrow(LoaderException::class, 'maxBytes');
});

it('loads from yaml raw content string via loadFromYaml', function (): void {
    $loader = Loader::defineDecodePolicy();
    $yaml = "arazzo: '1.0.0'\ninfo:\n  title: Test\n  version: '1.0'\nworkflows: []\n";

    $raw = $loader->loadFromYaml($yaml, 'custom.yaml');

    expect($raw->format)->toBe(Format::Yaml)
        ->and($raw->path)->toBe('custom.yaml')
        ->and($raw->data['arazzo'])->toBe('1.0.0')
        ->and($raw->data['info']['title'])->toBe('Test');
});

it('loads from json raw content string via loadFromJson', function (): void {
    $loader = Loader::defineDecodePolicy();
    $json = '{"arazzo":"1.0.0","info":{"title":"Test","version":"1.0"},"workflows":[]}';

    $raw = $loader->loadFromJson($json);

    expect($raw->format)->toBe(Format::Json)
        ->and($raw->path)->toBe('inline.json')
        ->and($raw->data['arazzo'])->toBe('1.0.0')
        ->and($raw->data['info']['title'])->toBe('Test');
});

it('loadFromYaml enforces decode policy', function (): void {
    $loader = Loader::defineDecodePolicy(DecodePolicy::default()->withMaxBytes(30));
    $yaml = "arazzo: '1.0.0'\ninfo:\n  title: Test\n  version: '1.0'\nworkflows: []\n";

    expect(fn () => $loader->loadFromYaml($yaml))
        ->toThrow(LoaderException::class, 'maxBytes');
});

it('creates loader with custom decoders via withDecoders', function (): void {
    $loader = Loader::withDecoders([]);
    expect($loader)->toBeInstanceOf(Loader::class);
});

it('throws unsupported format on load when decoder is not registered', function (): void {
    $loader = Loader::withDecoders([]);
    $tmp = tempnam(sys_get_temp_dir(), 'arz').'.yaml';
    file_put_contents($tmp, "foo: bar\n");
    try {
        $loader->load($tmp);
    } finally {
        @unlink($tmp);
    }
})->throws(LoaderException::class, 'Unsupported');

it('throws unsupported format on loadFromYaml when yaml decoder is missing', function (): void {
    $loader = Loader::withDecoders([]);
    $loader->loadFromYaml('foo: bar');
})->throws(LoaderException::class, 'Unsupported');

it('throws unsupported format on loadFromJson when json decoder is missing', function (): void {
    $loader = Loader::withDecoders([]);
    $loader->loadFromJson('{"foo": "bar"}');
})->throws(LoaderException::class, 'Unsupported');

it('throws not readable when file permissions forbid reading', function (): void {
    $tmp = tempnam(sys_get_temp_dir(), 'arz').'.yaml';
    file_put_contents($tmp, "foo: bar\n");
    chmod($tmp, 0000);
    try {
        makeLoader()->load($tmp);
    } finally {
        chmod($tmp, 0644);
        @unlink($tmp);
    }
})->throws(LoaderException::class, 'not readable');

it('throws read failed when file_get_contents fails', function (): void {
    if (!in_array('failstream', stream_get_wrappers(), true)) {
        class_alias(
            (new class()
            {
                public $context;

                public function stream_open(string $p, string $m, int $o, ?string &$opened_path): bool
                {
                    return false;
                }

                public function url_stat(string $p, int $f): array
                {
                    return ['mode' => 0100644];
                }
            })::class,
            'LoaderFailStreamWrapper',
        );
        stream_wrapper_register('failstream', 'LoaderFailStreamWrapper');
    }

    set_error_handler(static fn () => true);
    try {
        makeLoader()->load('failstream://test.yaml');
    } finally {
        restore_error_handler();
    }
})->throws(LoaderException::class, 'Failed to read file');
