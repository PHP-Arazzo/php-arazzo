<?php

declare(strict_types=1);

namespace Alama\Arazzo\Evaluation\Registries;

use Alama\Arazzo\Contracts\Attributes\AsCriterionPlugin;
use Alama\Arazzo\Contracts\Discovery\PluginDiscovery;
use Alama\Arazzo\Contracts\Interfaces\CriterionEvaluatorPluginInterface;
use Alama\Arazzo\Contracts\Spec\Enum\CriterionType;
use Alama\Arazzo\Contracts\Spec\SuccessCriterion;
use Alama\Arazzo\Expression\Interfaces\ExpressionEngineInterface;
use InvalidArgumentException;
use ReflectionClass;

/**
 * Registry for criterion evaluator plugins.
 *
 * Plugins are stored with an integer priority (higher = earlier). Auto-discovers
 * `#[AsCriterionPlugin]` classes declared in installed packages' `composer.json`
 * `extra.arazzo.plugins`.
 */
final class CriterionEvaluatorRegistry
{
    private static ?self $instance = null;

    /** @var array<string, array{plugin: CriterionEvaluatorPluginInterface, priority: int}> */
    private array $plugins = [];

    private function __construct(ExpressionEngineInterface $expression)
    {
        foreach (PluginDiscovery::instantiate(AsCriterionPlugin::class, [$expression]) as $plugin) {
            if ($plugin instanceof CriterionEvaluatorPluginInterface) {
                $this->register($plugin);
            }
        }
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

        $this->plugins[$plugin->name()] = [
            'plugin' => $plugin,
            'priority' => $priority,
        ];
    }

    public function resolve(CriterionType|SuccessCriterion $criterion): ?CriterionEvaluatorPluginInterface
    {
        foreach ($this->ordered() as $entry) {
            if ($entry['plugin']->supports($criterion)) {
                return $entry['plugin'];
            }
        }

        return null;
    }

    /** @return list<CriterionEvaluatorPluginInterface> */
    public function all(): array
    {
        return array_map(
            static fn (array $entry): CriterionEvaluatorPluginInterface => $entry['plugin'],
            $this->ordered(),
        );
    }

    /**
     * @return list<array{plugin: CriterionEvaluatorPluginInterface, priority: int}>
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
