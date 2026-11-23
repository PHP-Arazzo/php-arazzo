<?php

declare(strict_types=1);

namespace Alama\Arazzo\Evaluation\Registries;

use Alama\Arazzo\Contracts\Attributes\AsExpressionPlugin;
use Alama\Arazzo\Contracts\Discovery\PluginDiscovery;
use Alama\Arazzo\Contracts\Interfaces\ExpressionEvaluatorPluginInterface;
use Alama\Arazzo\Contracts\Spec\Enum\ExpressionType;
use Alama\Arazzo\Contracts\Spec\Expression;
use Alama\Arazzo\Expression\Interfaces\ExpressionEngineInterface;
use InvalidArgumentException;
use ReflectionClass;

/**
 * Registry for expression evaluator plugins.
 *
 * Keyed by {@see ExpressionType} (via `#[AsExpressionPlugin]`) or plugin name;
 * last-registered wins when overriding a type. Auto-discovers
 * `#[AsExpressionPlugin]` classes declared in installed packages'
 * `composer.json` `extra.arazzo.plugins` directories.
 */
final class ExpressionEvaluatorRegistry
{
    private static ?self $instance = null;

    /** @var array<string, ExpressionEvaluatorPluginInterface> */
    private array $plugins = [];

    private function __construct(ExpressionEngineInterface $expression)
    {
        foreach (PluginDiscovery::instantiate(AsExpressionPlugin::class, [$expression]) as $plugin) {
            if ($plugin instanceof ExpressionEvaluatorPluginInterface) {
                $this->register($plugin);
            }
        }
    }

    public static function create(?ExpressionEngineInterface $expression = null): self
    {
        if (self::$instance === null) {
            if ($expression === null) {
                throw new InvalidArgumentException('ExpressionEngineInterface is required to initialize ExpressionEvaluatorRegistry.');
            }
            self::$instance = new self($expression);
        }

        return self::$instance;
    }

    public static function reset(): void
    {
        self::$instance = null;
    }

    public function register(ExpressionEvaluatorPluginInterface $plugin): void
    {
        $key = self::resolveKey($plugin);
        // Move to end so last-registered wins on resolution.
        unset($this->plugins[$key]);
        $this->plugins[$key] = $plugin;
    }

    public function resolve(Expression $expression): ?ExpressionEvaluatorPluginInterface
    {
        foreach (array_reverse($this->plugins) as $plugin) {
            if ($plugin->supports($expression)) {
                return $plugin;
            }
        }

        return null;
    }

    /** @return list<ExpressionEvaluatorPluginInterface> */
    public function all(): array
    {
        return array_values(array_reverse($this->plugins));
    }

    private static function resolveKey(ExpressionEvaluatorPluginInterface $plugin): string
    {
        $attrs = (new ReflectionClass($plugin))->getAttributes(AsExpressionPlugin::class);
        if ($attrs !== []) {
            /** @var AsExpressionPlugin $attr */
            $attr = $attrs[0]->newInstance();

            return 'type:'.$attr->type->value;
        }

        return 'name:'.$plugin->name();
    }
}
