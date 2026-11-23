<?php

declare(strict_types=1);

namespace Alama\Arazzo\Expression\Registries;

use Alama\Arazzo\Contracts\Attributes\AsSelectorPlugin;
use Alama\Arazzo\Contracts\Discovery\PluginDiscovery;
use Alama\Arazzo\Contracts\Spec\Enum\ExpressionType;
use Alama\Arazzo\Expression\Interfaces\SelectorPluginInterface;
use InvalidArgumentException;
use ReflectionClass;

/**
 * Internal strategy registry for selector plugins inside `arazzo-expression`.
 *
 * Plugins are keyed by {@see ExpressionType}; last-registered wins when a
 * custom implementation replaces a built-in type. Auto-discovers
 * `#[AsSelectorPlugin]` classes declared in installed packages'
 * `composer.json` `extra.arazzo.plugins` directories.
 *
 * @internal stays out of the advertised contract; not part of the public API surface.
 */
final class SelectorPluginRegistry
{
    private static ?self $instance = null;

    /** @var array<string, SelectorPluginInterface> */
    private array $plugins = [];

    private function __construct()
    {
        foreach (PluginDiscovery::instantiate(AsSelectorPlugin::class) as $plugin) {
            if ($plugin instanceof SelectorPluginInterface) {
                $this->register($plugin);
            }
        }
    }

    public static function create(): self
    {
        return self::$instance ??= new self();
    }

    public static function reset(): void
    {
        self::$instance = null;
    }

    public function register(
        ExpressionType|SelectorPluginInterface $typeOrPlugin,
        ?SelectorPluginInterface $plugin = null,
    ): void {
        if ($typeOrPlugin instanceof SelectorPluginInterface) {
            $plugin = $typeOrPlugin;
            $type = self::resolveType($plugin);
        } else {
            $type = $typeOrPlugin;
            if ($plugin === null) {
                throw new InvalidArgumentException('Plugin must be provided when registering by ExpressionType.');
            }
        }

        // Last-registered wins for the given ExpressionType.
        $this->plugins[$type->value] = $plugin;
    }

    public function get(ExpressionType $type): ?SelectorPluginInterface
    {
        return $this->plugins[$type->value] ?? null;
    }

    public function has(ExpressionType $type): bool
    {
        return isset($this->plugins[$type->value]);
    }

    /**
     * @return list<string> Supported version tokens for the given type, or [] if no plugin is registered.
     */
    public function supportedVersions(ExpressionType $type): array
    {
        $plugin = $this->get($type);

        return $plugin !== null ? $plugin->supportedVersions() : [];
    }

    /** @return list<SelectorPluginInterface> */
    public function all(): array
    {
        return array_values($this->plugins);
    }

    private static function resolveType(SelectorPluginInterface $plugin): ExpressionType
    {
        $attrs = (new ReflectionClass($plugin))->getAttributes(AsSelectorPlugin::class);
        if ($attrs !== []) {
            /** @var AsSelectorPlugin $attr */
            $attr = $attrs[0]->newInstance();

            return $attr->type;
        }

        foreach (ExpressionType::cases() as $case) {
            if ($plugin->supports($case)) {
                return $case;
            }
        }

        throw new InvalidArgumentException('Cannot determine ExpressionType for selector plugin: missing #[AsSelectorPlugin] attribute or supports() matching.');
    }
}
