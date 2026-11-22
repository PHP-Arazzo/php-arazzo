<?php

declare(strict_types=1);

namespace Alama\Arazzo\Tests\Data;

use Alama\Arazzo\Contracts\Data\ResolvedOperationCollection;
use Alama\Arazzo\Contracts\Spec\Enum\SourceType;
use Alama\Arazzo\Contracts\Spec\NormalizedOpenApiOperation;
use Alama\Arazzo\Contracts\Spec\ResolvedOperation;
use Alama\Arazzo\Contracts\Spec\SourceDescription;

it('manages resolved operations collection immutably', function (): void {
    $sourceA = new SourceDescription('pets', 'pets.yaml', SourceType::Openapi);
    $sourceB = new SourceDescription('orders', 'orders.yaml', SourceType::Openapi);

    $normalizedA = new NormalizedOpenApiOperation(
        path: '/pets',
        method: 'get',
        resolvedServerUrl: 'https://api.example.com',
        pathParameters: [],
        queryParameters: [],
        headerParameters: [],
        cookieParameters: [],
        requestBodies: [],
        responses: [],
    );

    $normalizedB = new NormalizedOpenApiOperation(
        path: '/orders',
        method: 'post',
        resolvedServerUrl: 'https://api.example.com',
        pathParameters: [],
        queryParameters: [],
        headerParameters: [],
        cookieParameters: [],
        requestBodies: [],
        responses: [],
    );

    $opA = new ResolvedOperation(source: $sourceA, normalized: $normalizedA, operationName: 'listPets');
    $opB = new ResolvedOperation(source: $sourceB, normalized: $normalizedB, operationName: 'createOrder');

    $collection = new ResolvedOperationCollection([
        'listPets' => $opA,
        '$sourceDescriptions.pets.listPets' => $opA,
        'createOrder' => $opB,
        '$sourceDescriptions.orders.createOrder' => $opB,
    ]);

    expect($collection)->toHaveCount(4)
        ->and($collection->isEmpty())->toBeFalse()
        ->and($collection->has('listPets'))->toBeTrue()
        ->and($collection->get('listPets'))->toBe($opA)
        ->and($collection->has('nonExistent'))->toBeFalse()
        ->and($collection->get('nonExistent'))->toBeNull();

    $petsOnly = $collection->forSource('pets');
    expect($petsOnly)->toHaveCount(2)
        ->and($petsOnly->has('createOrder'))->toBeFalse()
        ->and($petsOnly->has('listPets'))->toBeTrue();

    $known = $collection->toKnownOperations();
    expect($known->has('pets', 'listPets'))->toBeTrue()
        ->and($known->has('orders', 'createOrder'))->toBeTrue();
});

it('handles empty collection factory', function (): void {
    $empty = ResolvedOperationCollection::empty();

    expect($empty)->toHaveCount(0)
        ->and($empty->isEmpty())->toBeTrue()
        ->and($empty->all())->toBeEmpty();
});
