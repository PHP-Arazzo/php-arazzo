<?php

declare(strict_types=1);

namespace Alama\Arazzo\Document\Parser;

/**
 * @SuppressWarnings("PHPMD.LongVariable")
 */
final readonly class YamlPolicy
{
    public const int DEFAULT_MAX_ALIASES = 100;

    public const bool DEFAULT_ALLOW_CUSTOM_TAGS = false;

    public const bool DEFAULT_ALLOW_OBJECT_DESERIALIZATION = false;

    public function __construct(
        public int $maxAliases = self::DEFAULT_MAX_ALIASES,
        public bool $allowCustomTags = self::DEFAULT_ALLOW_CUSTOM_TAGS,
        public bool $allowObjectDeserialization = self::DEFAULT_ALLOW_OBJECT_DESERIALIZATION,
    ) {}

    public static function default(): self
    {
        return new self();
    }

    public function withMaxAliases(int $maxAliases): self
    {
        return new self(
            maxAliases: $maxAliases,
            allowCustomTags: $this->allowCustomTags,
            allowObjectDeserialization: $this->allowObjectDeserialization,
        );
    }

    public function withAllowCustomTags(bool $allowCustomTags): self
    {
        return new self(
            maxAliases: $this->maxAliases,
            allowCustomTags: $allowCustomTags,
            allowObjectDeserialization: $this->allowObjectDeserialization,
        );
    }

    public function withAllowObjectDeserialization(bool $allowObjectDeserialization): self
    {
        return new self(
            maxAliases: $this->maxAliases,
            allowCustomTags: $this->allowCustomTags,
            allowObjectDeserialization: $allowObjectDeserialization,
        );
    }
}
