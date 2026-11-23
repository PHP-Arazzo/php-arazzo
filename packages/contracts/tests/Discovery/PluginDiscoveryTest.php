<?php

declare(strict_types=1);

namespace Alama\Arazzo\Tests\Contracts\Discovery;

use Alama\Arazzo\Contracts\Attributes\AsOperationExecutorPlugin;
use Alama\Arazzo\Contracts\Attributes\AsSourceNormalizerPlugin;
use Alama\Arazzo\Contracts\Attributes\AsValidationRulePlugin;
use Alama\Arazzo\Contracts\Discovery\PluginDiscovery;
use Alama\Arazzo\Contracts\Spec\Enum\OperationProtocol;
use Alama\Arazzo\Contracts\Validation\ValidationPhase;
use RuntimeException;

#[AsValidationRulePlugin]
final class ZeroArgPlugin {}

#[AsValidationRulePlugin(ValidationPhase::Schema)]
final class StaticCreatePlugin
{
    public function __construct(public readonly string $source) {}

    public static function create(): self
    {
        return new self('from-create');
    }
}

#[AsValidationRulePlugin]
final class RequiredDependencyPlugin
{
    public function __construct(public readonly string $required) {}
}

#[AsOperationExecutorPlugin(OperationProtocol::Http)]
final class DiBoundExecutorPlugin
{
    public function __construct(public readonly object $service) {}
}

afterEach(function (): void {
    PluginDiscovery::reset();
});

it('discovers plugins declared in installed packages composer.json plugin directories', function (): void {
    $normalizers = PluginDiscovery::classesWith(AsSourceNormalizerPlugin::class);
    $rules = PluginDiscovery::classesWith(AsValidationRulePlugin::class);
    $executors = PluginDiscovery::classesWith(AsOperationExecutorPlugin::class);

    expect($normalizers)->not->toBeEmpty()
        ->and($rules)->not->toBeEmpty()
        ->and($executors)->not->toBeEmpty();
});

it('filters override classes by attribute and instantiates stateless plugins via new or static create()', function (): void {
    PluginDiscovery::overrideClasses([
        ZeroArgPlugin::class,
        StaticCreatePlugin::class,
        DiBoundExecutorPlugin::class,
    ]);

    expect(PluginDiscovery::classesWith(AsValidationRulePlugin::class))
        ->toBe([ZeroArgPlugin::class, StaticCreatePlugin::class])
        ->and(PluginDiscovery::classesWith(AsOperationExecutorPlugin::class))
        ->toBe([DiBoundExecutorPlugin::class]);

    $instances = PluginDiscovery::instantiate(AsValidationRulePlugin::class);

    expect($instances)->toHaveCount(2)
        ->and($instances[0])->toBeInstanceOf(ZeroArgPlugin::class)
        ->and($instances[1])->toBeInstanceOf(StaticCreatePlugin::class)
        ->and($instances[1]->source)->toBe('from-create');
});

it('throws loudly when a stateless plugin requires constructor arguments without defaults or create()', function (): void {
    PluginDiscovery::overrideClasses([RequiredDependencyPlugin::class]);

    expect(fn () => PluginDiscovery::instantiate(AsValidationRulePlugin::class))
        ->toThrow(RuntimeException::class, 'cannot be auto-instantiated');
});
