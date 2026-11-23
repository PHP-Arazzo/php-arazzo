<?php

declare(strict_types=1);

namespace Alama\Arazzo\Runner;

use Alama\Arazzo\Contracts\Attributes\AsOperationExecutor;
use Alama\Arazzo\Contracts\Interfaces\OperationExecutorPluginInterface;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Enum\OperationProtocol;
use Alama\Arazzo\Contracts\Spec\Step;
use ReflectionClass;

/**
 * Concrete singleton registry of operation executor plugins.
 *
 * Plugins decorated with `#[AsOperationExecutor(OperationProtocol::...)]` are
 * keyed by their {@see OperationProtocol} enum (last-registered wins) and
 * dispatched in canonical protocol order (`SubWorkflow`, `Soap`, `Rpc`,
 * `AsyncApi`, `Http`). Unattributed custom executors are checked first in
 * reverse registration order so explicit overrides win without numeric priority.
 *
 * @internal stays out of the advertised contract; not part of the public API surface
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
        $attrs = (new ReflectionClass($plugin))->getAttributes(AsOperationExecutor::class);
        if ($attrs !== []) {
            /** @var AsOperationExecutor $attr */
            $attr = $attrs[0]->newInstance();
            $this->byProtocol[$attr->protocol->value] = $plugin;

            return;
        }

        // Unattributed custom executors: move to end so last-registered wins.
        unset($this->custom[$plugin->name()]);
        $this->custom[$plugin->name()] = $plugin;
    }

    public function resolve(Step $step, ArazzoDocument $document): ?OperationExecutorPluginInterface
    {
        foreach ($this->ordered() as $plugin) {
            if ($plugin->supports($step, $document)) {
                return $plugin;
            }
        }

        return null;
    }

    /** @return list<OperationExecutorPluginInterface> */
    public function all(): array
    {
        return $this->ordered();
    }

    /** @return list<OperationExecutorPluginInterface> */
    private function ordered(): array
    {
        $ordered = array_values(array_reverse($this->custom));

        foreach (OperationProtocol::cases() as $protocol) {
            if (isset($this->byProtocol[$protocol->value])) {
                $ordered[] = $this->byProtocol[$protocol->value];
            }
        }

        return $ordered;
    }
}
