<?php

declare(strict_types=1);

namespace Alama\Arazzo\Contracts\Data;

use Alama\Arazzo\Contracts\Spec\Enum\SourceType;
use Alama\Arazzo\Contracts\Spec\SourceDocument;

final readonly class SourceResolution
{
    public function __construct(
        public string $name,
        public string $url,
        public SourceResolutionStatus $status,
        public ?string $errorMessage = null,
        public ?SourceType $declaredType = null,
        public ?SourceType $detectedType = null,
        public ?SourceDocument $document = null,
    ) {}

    public static function valid(
        string $name,
        string $url,
        ?SourceType $detectedType = null,
        ?SourceDocument $document = null,
    ): self {
        return new self(
            name: $name,
            url: $url,
            status: SourceResolutionStatus::Ok,
            detectedType: $detectedType,
            document: $document,
        );
    }

    public static function unreadable(string $name, string $url, string $errorMessage): self
    {
        return new self(
            name: $name,
            url: $url,
            status: SourceResolutionStatus::Unreadable,
            errorMessage: $errorMessage,
        );
    }

    public static function unparseable(string $name, string $url, string $errorMessage): self
    {
        return new self(
            name: $name,
            url: $url,
            status: SourceResolutionStatus::Unparseable,
            errorMessage: $errorMessage,
        );
    }

    public static function typeMismatch(
        string $name,
        string $url,
        ?SourceType $declaredType,
        ?SourceType $detectedType,
    ): self {
        return new self(
            name: $name,
            url: $url,
            status: SourceResolutionStatus::TypeMismatch,
            declaredType: $declaredType,
            detectedType: $detectedType,
        );
    }

    public static function typeInferenceFailed(string $name, string $url, string $errorMessage): self
    {
        return new self(
            name: $name,
            url: $url,
            status: SourceResolutionStatus::TypeInferenceFailed,
            errorMessage: $errorMessage,
        );
    }

    public function isValid(): bool
    {
        return $this->status === SourceResolutionStatus::Ok;
    }

    public function isOk(): bool
    {
        return $this->isValid();
    }

    public function isUnreadable(): bool
    {
        return $this->status === SourceResolutionStatus::Unreadable;
    }

    public function isUnparseable(): bool
    {
        return $this->status === SourceResolutionStatus::Unparseable;
    }

    public function isTypeMismatch(): bool
    {
        return $this->status === SourceResolutionStatus::TypeMismatch;
    }

    public function isTypeInferenceFailed(): bool
    {
        return $this->status === SourceResolutionStatus::TypeInferenceFailed;
    }
}
