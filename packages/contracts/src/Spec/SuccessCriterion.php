<?php

declare(strict_types=1);

namespace Alama\Arazzo\Contracts\Spec;

use Alama\Arazzo\Contracts\Spec\Enum\CriterionType;

final readonly class SuccessCriterion
{
    public function __construct(
        public ?string $context,
        public string $condition,
        public ?CriterionType $type,
        public ?string $version = null,
    ) {}

    public function isSimple(): bool
    {
        return $this->type === CriterionType::Simple;
    }

    public function isRegex(): bool
    {
        return $this->type === CriterionType::Regex;
    }

    public function isJsonPath(): bool
    {
        return $this->type === CriterionType::JsonPath;
    }

    public function isXPath(): bool
    {
        return $this->type === CriterionType::XPath;
    }

    public function hasContext(): bool
    {
        return $this->context !== null && trim($this->context) !== '';
    }

    public function hasVersion(): bool
    {
        return $this->version !== null && trim($this->version) !== '';
    }
}
