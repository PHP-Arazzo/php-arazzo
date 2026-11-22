<?php

declare(strict_types=1);

namespace Alama\Arazzo\Runner;

use Alama\Arazzo\Contracts\Attributes\AsOperationExecutor;
use Alama\Arazzo\Contracts\Interfaces\OperationExecutorPluginInterface;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Step;
use ReflectionClass;

/**
 * Concrete singleton registry of operation executor plugins.
 *
 * Registration order does not matter: plugins are sorted by priority at
 * resolution time. Lower priority values are tried first.
 *
 * @internal stays out of the advertised contract; not part of the public API surface
 */
final class OperationExecutorRegistry
{
    private static ?self $instance = null;

    /** @var array<string, array{plugin: OperationExecutorPluginInterface, priority: int}> */
    private array $plugins = [];

    private function __construct() {}

    public static function create(): self
    {
        return self::$instance ??= new self();
    }

    public static function reset(): void
    {
        self::$instance = null;
    }

    public function register(OperationExecutorPluginInterface $plugin, ?int $priority = null): void
    {
        $ref = new ReflectionClass($plugin);
        $attrs = $ref->getAttributes(AsOperationExecutor::class);
        if (!empty($attrs)) {
            /** @var AsOperationExecutor $attr */
            $attr = $attrs[0]->newInstance();
            $priority ??= $attr->priority;
        }

        $priority ??= $plugin->priority();

        $this->plugins[$plugin->name()] = [
            'plugin' => $plugin,
            'priority' => $priority,
        ];
    }

    public function resolve(Step $step, ArazzoDocument $document): ?OperationExecutorPluginInterface
    {
        $sorted = array_values($this->plugins);
        usort($sorted, static fn (array $a, array $b): int => $a['priority'] <=> $b['priority']);

        foreach ($sorted as $entry) {
            if ($entry['plugin']->supports($step, $document)) {
                return $entry['plugin'];
            }
        }

        return null;
    }

    /** @return list<OperationExecutorPluginInterface> */
    public function all(): array
    {
        return array_values(array_map(static fn (array $entry): OperationExecutorPluginInterface => $entry['plugin'], $this->plugins));
    }
}
