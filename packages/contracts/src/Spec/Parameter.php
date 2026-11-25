<?php

declare(strict_types=1);

namespace Alama\Arazzo\Contracts\Spec;

use Alama\Arazzo\Contracts\Spec\Enum\ParameterIn;
use Alama\Arazzo\Contracts\Spec\Enum\ValueMode;

final readonly class Parameter
{
    /**
     * @param  Expression|Selector|scalar|array<mixed>|null  $value
     */
    public function __construct(
        public string $name,
        public ?ParameterIn $in,
        public mixed $value,
        public ?ValueMode $valueMode = null,
    ) {}

    public function isQuery(): bool
    {
        return $this->in === ParameterIn::Query;
    }

    public function isHeader(): bool
    {
        return $this->in === ParameterIn::Header;
    }

    public function isPath(): bool
    {
        return $this->in === ParameterIn::Path;
    }

    public function isBody(): bool
    {
        return $this->in === ParameterIn::Body;
    }

    public function isCookie(): bool
    {
        return $this->in === ParameterIn::Cookie;
    }

    public function isMetadata(): bool
    {
        return $this->in === ParameterIn::Metadata;
    }

    public function isQuerystring(): bool
    {
        return $this->in === ParameterIn::Querystring;
    }

    public function isVariable(): bool
    {
        return $this->in === ParameterIn::Variable;
    }

    public function isExpression(): bool
    {
        return $this->value instanceof Expression;
    }

    public function isSelector(): bool
    {
        return $this->value instanceof Selector;
    }
}
