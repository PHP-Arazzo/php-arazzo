<?php

declare(strict_types=1);

namespace Alama\Arazzo\Tests\Contracts\Exceptions;

use Alama\Arazzo\Contracts\Discovery\PluginInstantiator;
use Alama\Arazzo\Contracts\Exceptions\InvalidSpecVersionException;
use Alama\Arazzo\Contracts\Exceptions\InvalidStepTargetException;
use Alama\Arazzo\Contracts\Exceptions\PluginInstantiationException;
use Alama\Arazzo\Contracts\Exceptions\SchemaValidationException;
use Alama\Arazzo\Contracts\Exceptions\UnresolvableReusableReferenceException;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Components;
use Alama\Arazzo\Contracts\Spec\Enum\SpecVersion;
use Alama\Arazzo\Contracts\Spec\Info;
use Alama\Arazzo\Contracts\Spec\Reusable;
use Alama\Arazzo\Contracts\Spec\StepTarget;
use Alama\Arazzo\Contracts\Spec\WorkflowCollection;

final class NonPublicConstructorPluginFixture
{
    private function __construct() {}
}

it('throws domain exceptions constructed via static factories across contracts', function (): void {
    expect(fn () => SpecVersion::fromRaw('2.0.0'))
        ->toThrow(InvalidSpecVersionException::class, "Unsupported arazzo version '2.0.0'");

    expect(fn () => StepTarget::http())
        ->toThrow(InvalidStepTargetException::class, 'http target requires exactly one');

    expect(fn () => PluginInstantiator::instantiate(NonPublicConstructorPluginFixture::class, 'Attr'))
        ->toThrow(PluginInstantiationException::class, 'constructor is not public');

    $emptyDoc = new ArazzoDocument('1.0.0', new Info('T', null, null, '1'), [], WorkflowCollection::empty(), new Components([], [], [], []), []);
    expect(fn () => (new Reusable('$components.parameters.missing'))->getParamterComponent($emptyDoc))
        ->toThrow(UnresolvableReusableReferenceException::class, "Unresolvable reusable parameter reference '\$components.parameters.missing'");

    $schemaExc = SchemaValidationException::forStep('s1', [['path' => '/id', 'message' => 'bad']]);
    expect($schemaExc)->toBeInstanceOf(SchemaValidationException::class)
        ->and($schemaExc->stepId)->toBe('s1')
        ->and($schemaExc->getMessage())->toContain('/id');
});
