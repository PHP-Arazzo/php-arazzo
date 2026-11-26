<?php

declare(strict_types=1);

namespace Alama\Arazzo\Contracts\Spec;

use Alama\Arazzo\Contracts\Spec\Enum\SourceType;

final readonly class SourceDocument
{
    /**
     * @param  array<string, mixed>  $content
     */
    public function __construct(
        public string $name,
        public ?SourceType $type,
        public string $canonicalUri,
        public array $content,
        public ?string $selfUri = null,
    ) {}

    /**
     * Derive the effective base URI for relative references (Arazzo 1.1.0 §5.2).
     *
     * Returns Identity URI ($self) when present, otherwise normalized Retrieval URI ($canonicalUri).
     */
    public function baseUri(): string
    {
        return $this->selfUri ?? $this->canonicalUri;
    }
}
