<?php

declare(strict_types=1);

namespace Alama\Arazzo\Sources\Registries;

use Alama\Arazzo\Contracts\Attributes\AsSourceNormalizerPlugin;
use Alama\Arazzo\Contracts\Discovery\PluginDiscovery;
use Alama\Arazzo\Contracts\Interfaces\SourceNormalizerInterface;
use Alama\Arazzo\Contracts\Spec\Enum\SourceType;
use ReflectionClass;

/**
 * Public singleton registry of source normalizers.
 *
 * Keyed by {@see SourceType} (via `#[AsSourceNormalizerPlugin]`) or registration slot;
 * last-registered wins when overriding a source type. Auto-discovers
 * `#[AsSourceNormalizerPlugin]` classes declared in installed packages'
 * `composer.json` `extra.arazzo.plugins` directories.
 */
final class SourceNormalizerRegistry
{
    private static ?self $instance = null;

    /** @var array<string, array{normalizer: SourceNormalizerInterface, type: ?SourceType}> */
    private array $normalizers = [];

    private function __construct()
    {
        foreach (PluginDiscovery::instantiate(AsSourceNormalizerPlugin::class) as $normalizer) {
            if ($normalizer instanceof SourceNormalizerInterface) {
                $this->register($normalizer);
            }
        }
    }

    public static function create(): self
    {
        return self::$instance ??= new self();
    }

    public static function reset(): void
    {
        self::$instance = null;
    }

    public function register(SourceNormalizerInterface $normalizer): void
    {
        $type = null;
        $attrs = (new ReflectionClass($normalizer))->getAttributes(AsSourceNormalizerPlugin::class);
        if ($attrs !== []) {
            /** @var AsSourceNormalizerPlugin $attr */
            $attr = $attrs[0]->newInstance();
            $type = $attr->type;
        }

        $key = $type !== null ? 'type:'.$type->value : 'name:'.$normalizer->name();
        // Move to end so last-registered wins on resolution.
        unset($this->normalizers[$key]);
        $this->normalizers[$key] = [
            'normalizer' => $normalizer,
            'type' => $type,
        ];
    }

    public function get(SourceType $type): ?SourceNormalizerInterface
    {
        foreach (array_reverse($this->normalizers) as $entry) {
            if ($entry['type'] !== null) {
                if ($entry['type'] === $type) {
                    return $entry['normalizer'];
                }
            } elseif ($entry['normalizer']->supports($type)) {
                return $entry['normalizer'];
            }
        }

        return null;
    }
}
