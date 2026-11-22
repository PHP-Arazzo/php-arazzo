<?php

declare(strict_types=1);

namespace Alama\Arazzo\Sources\Resolver;

use Alama\Arazzo\Contracts\Attributes\AsSourceNormalizer;
use Alama\Arazzo\Contracts\Interfaces\SourceNormalizerInterface;
use Alama\Arazzo\Contracts\Spec\Enum\SourceType;
use ReflectionClass;

/**
 * Concrete singleton registry of source normalizers.
 *
 * SPI registry holding stateless normalizer implementations keyed by SourceType.
 *
 * @internal stays out of the advertised contract; not part of the public API surface
 */
final class SourceNormalizerRegistry
{
    private static ?self $instance = null;

    /** @var array<string, array{normalizer: SourceNormalizerInterface, priority: int, type: ?SourceType}> */
    private array $normalizers = [];

    private function __construct() {}

    public static function create(): self
    {
        return self::$instance ??= new self();
    }

    public static function reset(): void
    {
        self::$instance = null;
    }

    public function register(SourceNormalizerInterface $normalizer, ?int $priority = null): void
    {
        $type = null;
        $ref = new ReflectionClass($normalizer);
        $attrs = $ref->getAttributes(AsSourceNormalizer::class);
        if (!empty($attrs)) {
            /** @var AsSourceNormalizer $attr */
            $attr = $attrs[0]->newInstance();
            $priority ??= $attr->priority;
            $type = $attr->type;
        }

        $priority ??= $normalizer->priority();

        $this->normalizers[$normalizer->name()] = [
            'normalizer' => $normalizer,
            'priority' => $priority,
            'type' => $type,
        ];
    }

    public function get(SourceType $type): ?SourceNormalizerInterface
    {
        foreach ($this->ordered() as $entry) {
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

    /**
     * Highest priority first; stable so registration order breaks ties.
     *
     * @return list<array{normalizer: SourceNormalizerInterface, priority: int, type: ?SourceType}>
     */
    private function ordered(): array
    {
        $normalizers = array_values($this->normalizers);
        usort(
            $normalizers,
            static fn (array $a, array $b): int => $b['priority'] <=> $a['priority'],
        );

        return $normalizers;
    }
}
