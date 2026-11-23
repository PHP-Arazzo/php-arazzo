<?php

declare(strict_types=1);

namespace Alama\Arazzo\Runner\Registries;

use Alama\Arazzo\Contracts\Attributes\AsOperationExecutorPlugin;
use Alama\Arazzo\Contracts\Interfaces\OperationExecutorPluginInterface;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Enum\OperationProtocol;
use Alama\Arazzo\Contracts\Spec\Step;
use ReflectionClass;

/**
 * Concrete singleton registry of operation executor plugins.
 *
 * Plugins decorated with `#[AsOperationExecutorPlugin(OperationProtocol::...)]` are
 * keyed by their {@see OperationProtocol} enum (last-registered wins) and
 * dispatched in canonical protocol order (`SubWorkflow`, `Soap`, `Rpc`,
 * `AsyncApi`, `Http`). Unattributed custom executors are checked first in
 * reverse registration order so explicit overrides win without numeric priority.
 */
final class OperationExecutorRegistry
{
    private static ?self $instance = null;

    /** @var array<string, OperationExecutorPluginInterface> */
    private array $byProtocol = [];

    /** @var array<string, OperationExecutorPluginInterface> */
    private array $custom = [];

    private function __construct() {}

    public static function create(): self
    {
        return self::$instance ??= new self();
    }

    public static function reset(): void
    {
        self::$instance = null;
    }

    public function register(OperationExecutorPluginInterface $plugin): void
    {
        $protocol = self::protocolOf($plugin);
        if ($protocol !== null) {
            $this->byProtocol[$protocol->value] = $plugin;

            return;
        }

        // Unattributed custom executors: move to end so last-registered wins.
        unset($this->custom[$plugin->name()]);
        $this->custom[$plugin->name()] = $plugin;
    }

    /**
     * Resolve the highest-precedence executor supporting the step, optionally
     * layering per-instance overrides without mutating the shared registry.
     *
     * @param  list<OperationExecutorPluginInterface>  $overrides
     */
    public function resolve(Step $step, ArazzoDocument $document, array $overrides = []): ?OperationExecutorPluginInterface
    {
        foreach ($this->ordered($overrides) as $plugin) {
            if ($plugin->supports($step, $document)) {
                return $plugin;
            }
        }

        return null;
    }

    /**
     * Return all executors in canonical dispatch order, optionally layered with
     * per-instance overrides.
     *
     * @param  list<OperationExecutorPluginInterface>  $overrides
     * @return list<OperationExecutorPluginInterface>
     */
    public function all(array $overrides = []): array
    {
        return $this->ordered($overrides);
    }

    /**
     * Return an isolated snapshot copy of this registry for per-graph wiring.
     *
     * @param  list<OperationExecutorPluginInterface>  $overrides
     */
    public function snapshot(array $overrides = []): self
    {
        $copy = new self();
        $copy->byProtocol = $this->byProtocol;
        $copy->custom = $this->custom;

        foreach ($overrides as $plugin) {
            $copy->register($plugin);
        }

        return $copy;
    }

    /**
     * @param  list<OperationExecutorPluginInterface>  $overrides
     * @return list<OperationExecutorPluginInterface>
     */
    private function ordered(array $overrides = []): array
    {
        $byProtocol = $this->byProtocol;
        $custom = $this->custom;

        foreach ($overrides as $plugin) {
            $protocol = self::protocolOf($plugin);
            if ($protocol !== null) {
                $byProtocol[$protocol->value] = $plugin;

                continue;
            }

            unset($custom[$plugin->name()]);
            $custom[$plugin->name()] = $plugin;
        }

        $ordered = array_values(array_reverse($custom));

        foreach (OperationProtocol::cases() as $protocol) {
            if (isset($byProtocol[$protocol->value])) {
                $ordered[] = $byProtocol[$protocol->value];
            }
        }

        return $ordered;
    }

    private static function protocolOf(OperationExecutorPluginInterface $plugin): ?OperationProtocol
    {
        $attrs = (new ReflectionClass($plugin))->getAttributes(AsOperationExecutorPlugin::class);
        if ($attrs === []) {
            return null;
        }

        /** @var AsOperationExecutorPlugin $attr */
        $attr = $attrs[0]->newInstance();

        return $attr->protocol;
    }
}
