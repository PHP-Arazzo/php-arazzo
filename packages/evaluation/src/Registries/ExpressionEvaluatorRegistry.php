<?php

declare(strict_types=1);

namespace Alama\Arazzo\Evaluation\Registries;

use Alama\Arazzo\Contracts\Attributes\AsExpressionPlugin;
use Alama\Arazzo\Contracts\Discovery\PluginDiscovery;
use Alama\Arazzo\Contracts\Interfaces\ExpressionEvaluatorPluginInterface;
use Alama\Arazzo\Contracts\Spec\Expression;
use Alama\Arazzo\Expression\Interfaces\ExpressionEngineInterface;
use InvalidArgumentException;
use ReflectionClass;

/**
 * Registry for expression evaluator plugins.
 *
 * Plugins are stored with an integer priority (higher = earlier). Auto-discovers
 * `#[AsExpressionPlugin]` classes declared in installed packages' `composer.json`
 * `extra.arazzo.plugins`.
 */
final class ExpressionEvaluatorRegistry
{
    private static ?self $instance = null;

    /** @var array<string, array{plugin: ExpressionEvaluatorPluginInterface, priority: int}> */
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

        $this->plugins[$plugin->name()] = [
            'plugin' => $plugin,
            'priority' => $priority,
        ];
    }

    public function resolve(Expression $expression): ?ExpressionEvaluatorPluginInterface
    {
        foreach ($this->ordered() as $entry) {
            if ($entry['plugin']->supports($expression)) {
                return $entry['plugin'];
            }
        }

        return null;
    }

    /** @return list<ExpressionEvaluatorPluginInterface> */
    public function all(): array
    {
        return array_map(
            static fn (array $entry): ExpressionEvaluatorPluginInterface => $entry['plugin'],
            $this->ordered(),
        );
    }

    /**
     * @return list<array{plugin: ExpressionEvaluatorPluginInterface, priority: int}>
     */
    private function ordered(): array
    {
        $entries = array_values($this->plugins);
        usort(
            $entries,
            static fn (array $a, array $b): int => $b['priority'] <=> $a['priority'],
        );

        return $entries;
    }
}
