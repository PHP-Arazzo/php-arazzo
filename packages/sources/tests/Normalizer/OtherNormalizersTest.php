<?php

declare(strict_types=1);

namespace Alama\Arazzo\Tests\Normalizer;

use Alama\Arazzo\Sources\Normalizer\OpenApi31Normalizer;

it('open api 31 normalizer normalizes like 30', function (): void {
    // 3.1 documents are structurally identical for everything this
    // pipeline consumes; the normalizer is a documented passthrough.
    $normalizer = new OpenApi31Normalizer();
    $normalized = $normalizer->normalize([
        'servers' => [['url' => 'https://api.test']],
        'paths' => [
            '/test' => [
                'get' => [
                    'responses' => ['200' => ['description' => 'ok']],
                ],
            ],
        ],
    ], '/test', 'get');

    expect($normalized->path)->toBe('/test')
        ->and($normalized->method)->toBe('get')
        ->and($normalized->resolvedServerUrl)->toBe('https://api.test');
});
