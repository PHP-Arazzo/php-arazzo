<?php

declare(strict_types=1);

namespace Alama\Arazzo\Evaluation\Registries;

use Alama\Arazzo\Contracts\Attributes\AsCriterionPlugin;
use Alama\Arazzo\Contracts\Interfaces\CriterionEvaluatorPluginInterface;
use Alama\Arazzo\Contracts\Spec\Enum\CriterionType;
use Alama\Arazzo\Contracts\Spec\SuccessCriterion;
use Alama\Arazzo\Evaluation\Plugins\JsonPathCriterionPlugin;
use Alama\Arazzo\Expression\Interfaces\ExpressionEngineInterface;
use InvalidArgumentException;
use ReflectionClass;

/**
 * Registry for criterion evaluator plugins.
 *
 * Plugins are stored with an integer priority (higher = earlier). The registry
 * returns the first plugin whose {@see CriterionEvaluatorPluginInterface::supports()}
 * returns true.
 */
final class CriterionEvaluatorRegistry
{
    private static ?self $instance = null;

    /** @var array<int, list<CriterionEvaluatorPluginInterface>> */
    private array $plugins = [];

    private function __construct(ExpressionEngineInterface $expression)
    {
        $this->register(new JsonPathCriterionPlugin($expression), 0);
    }

    public static function create(?ExpressionEngineInterface $expression = null): self
    {
        if (self::$instance === null) {
            if ($expression === null) {
                throw new InvalidArgumentException('ExpressionEngineInterface is required to initialize CriterionEvaluatorRegistry.');
            }
            self::$instance = new self($expression);
        }

        return self::$instance;
    }

    public static function reset(): void
    {
        self::$instance = null;
    }

    public function register(CriterionEvaluatorPluginInterface $plugin, ?int $priority = null): void
    {
        if ($priority === null) {
            $ref = new ReflectionClass($plugin);
            $attrs = $ref->getAttributes(AsCriterionPlugin::class);
            if (!empty($attrs)) {
                /** @var AsCriterionPlugin $attr */
                $attr = $attrs[0]->newInstance();
                $priority = $attr->priority;
            }
        }

        $priority ??= $plugin->priority();

        $this->plugins[$priority][] = $plugin;
        krsort($this->plugins);
    }

    public function resolve(CriterionType|SuccessCriterion $criterion): ?CriterionEvaluatorPluginInterface
    {
        foreach ($this->plugins as $priorityPlugins) {
            foreach ($priorityPlugins as $plugin) {
                if ($plugin->supports($criterion)) {
                    return $plugin;
                }
            }
        }

        return null;
    }

    /** @return CriterionEvaluatorPluginInterface[] */
    public function all(): array
    {
        $flat = [];
        foreach ($this->plugins as $priorityPlugins) {
            $flat = array_merge($flat, $priorityPlugins);
        }

        return $flat;
    }
}
