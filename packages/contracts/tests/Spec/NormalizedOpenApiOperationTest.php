<?php

declare(strict_types=1);

use Alama\Arazzo\Contracts\Spec\NormalizedOpenApiOperation;

it('test_factory_defaults', function () {
    $operation = new NormalizedOpenApiOperation(path: '/pets', method: 'get');

    expect($operation->path)->toBe('/pets')
        ->and($operation->method)->toBe('get')
        ->and($operation->resolvedServerUrl)->toBeNull()
        ->and($operation->pathParameters)->toBe([])
        ->and($operation->queryParameters)->toBe([])
        ->and($operation->headerParameters)->toBe([])
        ->and($operation->cookieParameters)->toBe([])
        ->and($operation->requestBodies)->toBe([])
        ->and($operation->responses)->toBe([]);
});
