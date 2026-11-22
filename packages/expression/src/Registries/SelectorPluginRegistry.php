<?php

declare(strict_types=1);

namespace Alama\Arazzo\Expression\Registries;

use Alama\Arazzo\Contracts\Attributes\AsSelectorPlugin;
use Alama\Arazzo\Contracts\Spec\Enum\ExpressionType;
use Alama\Arazzo\Expression\Interfaces\SelectorPluginInterface;
use Alama\Arazzo\Expression\JsonPathEvaluator;
use Alama\Arazzo\Expression\Plugins\JsonPathSelectorPlugin;
use Alama\Arazzo\Expression\Plugins\JsonPointerSelectorPlugin;
use Alama\Arazzo\Expression\Plugins\XpathSelectorPlugin;
use Alama\Arazzo\Expression\Xpath\Xpath31Evaluator;
use InvalidArgumentException;
use ReflectionClass;

/**
 * Concrete singleton registry for selector plugins.
 *
 * Plugins are keyed by {@see ExpressionType} and stored with an integer
 * priority (higher = earlier).
 */
final class SelectorPluginRegistry
{
    private static ?self $instance = null;

    /** @var array<string, array<int, list<SelectorPluginInterface>>> */
    private array $plugins = [];

    private function __construct()
    {
        $this->register(new JsonPathSelectorPlugin(new JsonPathEvaluator()));
        $this->register(new JsonPointerSelectorPlugin());
        $this->register(new XpathSelectorPlugin(new Xpath31Evaluator()));
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
        int $priority = 0,
    ): void {
        if ($typeOrPlugin instanceof SelectorPluginInterface) {
            $plugin = $typeOrPlugin;
            $type = null;

            $ref = new ReflectionClass($plugin);
            $attrs = $ref->getAttributes(AsSelectorPlugin::class);
            if (!empty($attrs)) {
                /** @var AsSelectorPlugin $attr */
                $attr = $attrs[0]->newInstance();
                $type = $attr->type;
                $priority = $attr->priority;
            }

            if ($type === null) {
                foreach (ExpressionType::cases() as $case) {
                    if ($plugin->supports($case)) {
                        $type = $case;
                        break;
                    }
                }
            }

            if ($type === null) {
                throw new InvalidArgumentException('Cannot determine ExpressionType for selector plugin: missing #[AsSelectorPlugin] attribute or supports() matching.');
            }
        } else {
            $type = $typeOrPlugin;
            if ($plugin === null) {
                throw new InvalidArgumentException('Plugin must be provided when registering by ExpressionType.');
            }
        }

        $this->plugins[$type->value][$priority][] = $plugin;
        krsort($this->plugins[$type->value]);
    }

    public function get(ExpressionType $type): ?SelectorPluginInterface
    {
        $plugins = $this->plugins[$type->value] ?? [];

        foreach ($plugins as $priorityPlugins) {
            foreach ($priorityPlugins as $plugin) {
                return $plugin;
            }
        }

        return null;
    }

    public function has(ExpressionType $type): bool
    {
        return $this->get($type) !== null;
    }

    /**
     * @return list<string> Supported version tokens for the given type, or [] if no plugin is registered.
     */
    public function supportedVersions(ExpressionType $type): array
    {
        $plugin = $this->get($type);

        return $plugin !== null ? $plugin->supportedVersions() : [];
    }

    /** @return SelectorPluginInterface[] */
    public function all(): array
    {
        $flat = [];

        foreach ($this->plugins as $typePlugins) {
            foreach ($typePlugins as $priorityPlugins) {
                $flat = array_merge($flat, $priorityPlugins);
            }
        }

        return $flat;
    }
}
