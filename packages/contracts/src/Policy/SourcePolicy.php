<?php

declare(strict_types=1);

namespace Alama\Arazzo\Contracts\Policy;

/**
 * Strict, immutable execution policy addressed to a single Arazzo Source
 * Description (`sourceDescriptions[*].name`), preventing credentials or
 * endpoint overrides from leaking across APIs in multi-source workflows.
 */
final readonly class SourcePolicy
{
    /**
     * @param  array<string, Credential>  $credentials  Keyed by security scheme name
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $extensions  Protocol-specific escape hatch
     */
    public function __construct(
        public ServerTarget $target = new ServerTarget(),
        public array $credentials = [],
        public array $headers = [],
        public SourceTransportPolicy $transport = new SourceTransportPolicy(),
        public array $extensions = [],
    ) {}

    public static function default(): self
    {
        return new self();
    }

    public function server(): ?string
    {
        return $this->target->url;
    }

    /**
     * @return array<string, string>
     */
    public function serverVariables(): array
    {
        return $this->target->variables;
    }

    public function withServer(?string $server): self
    {
        return new self($this->target->withUrl($server), $this->credentials, $this->headers, $this->transport, $this->extensions);
    }

    /**
     * @param  array<string, string>  $variables
     */
    public function withServerVariables(array $variables): self
    {
        return new self($this->target->withVariables($variables), $this->credentials, $this->headers, $this->transport, $this->extensions);
    }

    public function withCredential(string $schemeName, Credential $credential): self
    {
        $credentials = $this->credentials;
        $credentials[$schemeName] = $credential;

        return new self($this->target, $credentials, $this->headers, $this->transport, $this->extensions);
    }

    public function credential(string $schemeName): ?Credential
    {
        return $this->credentials[$schemeName] ?? null;
    }

    public function withHeader(string $name, string $value): self
    {
        $headers = $this->headers;
        $headers[$name] = $value;

        return new self($this->target, $this->credentials, $headers, $this->transport, $this->extensions);
    }

    /**
     * @param  array<string, string>  $headers
     */
    public function withHeaders(array $headers): self
    {
        return new self($this->target, $this->credentials, $headers, $this->transport, $this->extensions);
    }

    public function timeoutSeconds(): ?float
    {
        return $this->transport->timeoutSeconds;
    }

    public function withTimeoutSeconds(?float $timeoutSeconds): self
    {
        return new self($this->target, $this->credentials, $this->headers, $this->transport->withTimeoutSeconds($timeoutSeconds), $this->extensions);
    }

    public function strictValidation(): ?bool
    {
        return $this->transport->strictValidation;
    }

    public function withStrictValidation(?bool $strictValidation): self
    {
        return new self($this->target, $this->credentials, $this->headers, $this->transport->withStrictValidation($strictValidation), $this->extensions);
    }

    public function withExtension(string $key, mixed $value): self
    {
        $extensions = $this->extensions;
        $extensions[$key] = $value;

        return new self($this->target, $this->credentials, $this->headers, $this->transport, $extensions);
    }

    public function extension(string $key): mixed
    {
        return $this->extensions[$key] ?? null;
    }
}
