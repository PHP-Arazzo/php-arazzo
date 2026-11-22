<?php

declare(strict_types=1);

namespace Alama\Arazzo\Tests\Validator;

use Alama\Arazzo\Document\KnownOperations;

it('starts empty with none()', function (): void {
    $ops = KnownOperations::none();

    expect($ops->covers('petstore'))->toBeFalse()
        ->and($ops->has('petstore', 'getPet'))->toBeFalse()
        ->and($ops->toArray())->toBe([]);
});

it('records operations per source immutably', function (): void {
    $ops = KnownOperations::none()
        ->withSource('petstore', ['getPet', 'createPet'])
        ->withOperation('auth', 'login');

    expect($ops->covers('petstore'))->toBeTrue()
        ->and($ops->covers('auth'))->toBeTrue()
        ->and($ops->covers('unknown'))->toBeFalse()
        ->and($ops->has('petstore', 'getPet'))->toBeTrue()
        ->and($ops->has('petstore', 'deletePet'))->toBeFalse()
        ->and($ops->has('auth', 'login'))->toBeTrue()
        ->and($ops->toArray())->toBe([
            'petstore' => ['getPet' => true, 'createPet' => true],
            'auth' => ['login' => true],
        ]);
});

it('supports withSource with empty list to mark source as covered with zero operations', function (): void {
    $ops = KnownOperations::none()->withSource('emptyApi', []);

    expect($ops->covers('emptyApi'))->toBeTrue()
        ->and($ops->has('emptyApi', 'anyOp'))->toBeFalse()
        ->and($ops->toArray())->toBe(['emptyApi' => []]);
});
