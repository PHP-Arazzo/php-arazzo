<?php

declare(strict_types=1);

namespace Alama\Arazzo\Tests\Expression\Registries;

use Alama\Arazzo\Contracts\Attributes\AsSelectorPlugin;
use Alama\Arazzo\Contracts\Spec\Enum\ExpressionType;
use Alama\Arazzo\Expression\Interfaces\SelectorPluginInterface;
use Alama\Arazzo\Expression\Registries\SelectorPluginRegistry;

afterEach(function (): void {
    SelectorPluginRegistry::reset();
});

it('returns the same singleton instance from create()', function (): void {
    $instance1 = SelectorPluginRegistry::create();
    $instance2 = SelectorPluginRegistry::create();

    expect($instance1)->toBe($instance2);
});

it('pre-populates default plugins on creation', function (): void {
    $registry = SelectorPluginRegistry::create();

    expect($registry->has(ExpressionType::JsonPath))->toBeTrue()
        ->and($registry->has(ExpressionType::JsonPointer))->toBeTrue()
        ->and($registry->has(ExpressionType::XPath))->toBeTrue();
});

it('resets singleton state via reset()', function (): void {
    $instance1 = SelectorPluginRegistry::create();
    SelectorPluginRegistry::reset();
    $instance2 = SelectorPluginRegistry::create();

    expect($instance1)->not->toBe($instance2);
});

it('registers custom plugins with priority and explicit type', function (): void {
    $registry = SelectorPluginRegistry::create();
    $customPlugin = new class() implements SelectorPluginInterface
    {
        public function supports(ExpressionType $type): bool
        {
            return $type === ExpressionType::JsonPath;
        }

        public function evaluate(mixed $rootValue, string $expression, ?string $version, ?array $namespaces = null): mixed
        {
            return 'custom';
        }

        public function supportedVersions(): array
        {
            return ['custom-1'];
        }
    };

    $registry->register(ExpressionType::JsonPath, $customPlugin, priority: 100);

    expect($registry->get(ExpressionType::JsonPath))->toBe($customPlugin);
});

it('registers plugins via #[AsSelectorPlugin] attribute', function (): void {
    $registry = SelectorPluginRegistry::create();

    $attributedPlugin = new #[AsSelectorPlugin(ExpressionType::JsonPath, priority: 200)] class() implements SelectorPluginInterface
    {
        public function supports(ExpressionType $type): bool
        {
            return $type === ExpressionType::JsonPath;
        }

        public function evaluate(mixed $rootValue, string $expression, ?string $version, ?array $namespaces = null): mixed
        {
            return 'attributed';
        }

        public function supportedVersions(): array
        {
            return ['attr-1'];
        }
    };

    $registry->register($attributedPlugin);

    expect($registry->get(ExpressionType::JsonPath))->toBe($attributedPlugin);
});
