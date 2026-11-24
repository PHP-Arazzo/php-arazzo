<?php

declare(strict_types=1);

namespace Alama\Arazzo\Evaluation\Registries;

use Alama\Arazzo\Contracts\Attributes\AsCriterionPlugin;
use Alama\Arazzo\Contracts\Discovery\PluginDiscovery;
use Alama\Arazzo\Contracts\Interfaces\CriterionEvaluatorPluginInterface;
use Alama\Arazzo\Contracts\Spec\Enum\CriterionType;
use Alama\Arazzo\Contracts\Spec\SuccessCriterion;
use Alama\Arazzo\Evaluation\Exceptions\RegistryInitializationException;
use Alama\Arazzo\Expression\Interfaces\ExpressionEngineInterface;
use ReflectionClass;

/**
 * Registry for criterion evaluator plugins.
 *
 * Keyed by {@see CriterionType} (via `#[AsCriterionPlugin]`) or plugin name;
 * last-registered wins when overriding a type. Auto-discovers
 * `#[AsCriterionPlugin]` classes declared in installed packages'
 * `composer.json` `extra.arazzo.plugins` directories.
 */
final class CriterionEvaluatorRegistry
{
    private static ?self $instance = null;

    /** @var array<string, CriterionEvaluatorPluginInterface> */
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
                throw RegistryInitializationException::missingExpressionEngine('CriterionEvaluatorRegistry');
            }
            self::$instance = new self($expression);
        }

        return self::$instance;
    }

    public static function reset(): void
    {
        self::$instance = null;
    }

    public function register(CriterionEvaluatorPluginInterface $plugin): void
    {
        $key = self::resolveKey($plugin);
        // Move to end so last-registered wins on resolution.
        unset($this->plugins[$key]);
        $this->plugins[$key] = $plugin;
    }

    public function resolve(CriterionType|SuccessCriterion $criterion): ?CriterionEvaluatorPluginInterface
    {
        foreach (array_reverse($this->plugins) as $plugin) {
            if ($plugin->supports($criterion)) {
                return $plugin;
            }
        }

        return null;
    }

    /** @return list<CriterionEvaluatorPluginInterface> */
    public function all(): array
    {
        return array_values(array_reverse($this->plugins));
    }

    private static function resolveKey(CriterionEvaluatorPluginInterface $plugin): string
    {
        $attrs = (new ReflectionClass($plugin))->getAttributes(AsCriterionPlugin::class);
        if ($attrs !== []) {
            /** @var AsCriterionPlugin $attr */
            $attr = $attrs[0]->newInstance();

            return 'type:'.$attr->type->value;
        }

        return 'name:'.$plugin->name();
    }
}
