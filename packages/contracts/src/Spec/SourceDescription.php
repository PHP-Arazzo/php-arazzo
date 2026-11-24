<?php

declare(strict_types=1);

namespace Alama\Arazzo\Contracts\Spec;

use Alama\Arazzo\Contracts\Spec\Enum\SourceType;

final readonly class SourceDescription
{
    public function __construct(
        public string $name,
        public string $url,
        public SourceType $type,
    ) {}

    public function isArazzo(): bool
    {
        return $this->type === SourceType::Arazzo;
    }

    public function isOpenApi(): bool
    {
        return $this->type === SourceType::Openapi;
    }

    public function isWsdl(): bool
    {
        return $this->type === SourceType::Wsdl;
    }

    public function isProtobuf(): bool
    {
        return $this->type === SourceType::Protobuf;
    }

    public function scheme(): string
    {
        $raw = parse_url($this->url, PHP_URL_SCHEME);

        return (is_string($raw) && $raw !== '') ? strtolower($raw) : 'file';
    }
}
