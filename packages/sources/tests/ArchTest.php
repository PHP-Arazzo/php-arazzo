<?php

declare(strict_types=1);

it('maps the sources namespace onto the package src directory', function (): void {
    $root = json_decode(
        (string) file_get_contents(__DIR__.'/../../../composer.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($root['autoload']['psr-4'])->toHaveKey('Alama\\Arazzo\\Sources\\')
        ->and($root['autoload']['psr-4']['Alama\\Arazzo\\Sources\\'])->toBe('packages/sources/src/')
        ->and($root['autoload-dev']['psr-4']['Alama\\Arazzo\\Tests\\Sources\\'])->toContain('packages/sources/tests')
        ->and(is_dir(__DIR__.'/../src'))->toBeTrue();
});

arch('sources does not depend on outer layers')
    ->expect('Alama\Arazzo\Sources')
    ->not->toUse('Alama\Arazzo\Runner')
    ->not->toUse('Alama\Arazzo\Cli')
    ->not->toUse('Alama\Arazzo\Protocol\Http')
    ->not->toUse('Alama\Arazzo\Protocol\Soap')
    ->not->toUse('Alama\Arazzo\Protocol\Rpc')
    ->not->toUse('Alama\Arazzo\Runtime')
    ->not->toUse('Illuminate');

arch('sources does not depend on cebe/php-openapi (zero-vendor purity)')
    ->expect('Alama\Arazzo\Sources')
    ->not->toUse('cebe\openapi');

arch('sources facade is final and readonly')
    ->expect('Alama\Arazzo\Sources\Sources')
    ->toBeFinal()
    ->toBeReadonly();

it('keeps protocol namespaces out of the sources package', function (): void {
    $needle = 'Alama\\Arazzo\\Protocol\\';
    $violations = [];

    foreach ([__DIR__.'/../src', __DIR__] as $root) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php' || $file->getPathname() === __FILE__) {
                continue;
            }

            if (str_contains((string) file_get_contents($file->getPathname()), $needle)) {
                $violations[] = str_replace(__DIR__.'/..'.DIRECTORY_SEPARATOR, '', $file->getPathname());
            }
        }
    }

    expect($violations)->toBeEmpty();
});
