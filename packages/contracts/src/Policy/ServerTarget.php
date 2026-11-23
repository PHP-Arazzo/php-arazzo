<?php

declare(strict_types=1);

namespace Alama\Arazzo\Contracts\Policy;

/**
 * Immutable server URL and template-variable override scoped to a single source description.
 */
final readonly class ServerTarget
{
    /**
     * @param  array<string, string>  $variables
     */
    public function __construct(
        public ?string $url = null,
        public array $variables = [],
    ) {}

    public static function default(): self
    {
        return new self();
    }

    public function withUrl(?string $url): self
    {
        return new self($url, $this->variables);
    }

    /**
     * @param  array<string, string>  $variables
     */
    public function withVariables(array $variables): self
    {
        return new self($this->url, $variables);
    }
}
