<?php

declare(strict_types=1);

namespace Alama\Arazzo\Evaluation\Registries;

use Alama\Arazzo\Contracts\Attributes\AsExpressionPlugin;
use Alama\Arazzo\Contracts\Interfaces\ExpressionEvaluatorPluginInterface;
use Alama\Arazzo\Contracts\Spec\Expression;
use Alama\Arazzo\Evaluation\Plugins\JsonPathExpressionPlugin;
use Alama\Arazzo\Expression\Interfaces\ExpressionEngineInterface;
use InvalidArgumentException;
use ReflectionClass;

/**
 * Registry for expression evaluator plugins.
 *
 * Plugins are stored with an integer priority (higher = earlier). The registry
 * returns the first plugin whose {@see ExpressionEvaluatorPluginInterface::supports()}
 * returns true.
 */
final class ExpressionEvaluatorRegistry
{
    private static ?self $instance = null;

    /** @var array<int, list<ExpressionEvaluatorPluginInterface>> */
    private array $plugins = [];

    private function __construct(ExpressionEngineInterface $expression)
    {
        $this->register(new JsonPathExpressionPlugin($expression), 0);
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

    public function register(ExpressionEvaluatorPluginInterface $plugin, ?int $priority = null): void
    {
        if ($priority === null) {
            $ref = new ReflectionClass($plugin);
            $attrs = $ref->getAttributes(AsExpressionPlugin::class);
            if (!empty($attrs)) {
                /** @var AsExpressionPlugin $attr */
                $attr = $attrs[0]->newInstance();
                $priority = $attr->priority;
            }
        }

        $priority ??= $plugin->priority();

        $this->plugins[$priority][] = $plugin;
        krsort($this->plugins); // higher priority first
    }

    public function resolve(Expression $expression): ?ExpressionEvaluatorPluginInterface
    {
        foreach ($this->plugins as $priorityPlugins) {
            foreach ($priorityPlugins as $plugin) {
                if ($plugin->supports($expression)) {
                    return $plugin;
                }
            }
        }

        return null;
    }

    /** @return ExpressionEvaluatorPluginInterface[] */
    public function all(): array
    {
        $flat = [];
        foreach ($this->plugins as $priorityPlugins) {
            $flat = array_merge($flat, $priorityPlugins);
        }

        return $flat;
    }
}
