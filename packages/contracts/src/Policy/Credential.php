<?php

declare(strict_types=1);

namespace Alama\Arazzo\Contracts\Policy;

use SensitiveParameter;

/**
 * Immutable, secret-redacting credential bound to a single source description's
 * security scheme inside a {@see SourcePolicy}.
 */
final readonly class Credential
{
    private function __construct(
        public CredentialType $type,
        #[SensitiveParameter]
        public ?string $value = null,
        public ?string $username = null,
        #[SensitiveParameter]
        public ?string $password = null,
        public ?string $tokenType = null,
    ) {}

    public static function apiKey(#[SensitiveParameter] string $value): self
    {
        return new self(type: CredentialType::ApiKey, value: $value);
    }

    public static function bearer(#[SensitiveParameter] string $token): self
    {
        return new self(type: CredentialType::Bearer, value: $token, tokenType: 'Bearer');
    }

    public static function basic(string $username, #[SensitiveParameter] string $password): self
    {
        return new self(type: CredentialType::Basic, username: $username, password: $password);
    }

    public static function oauth2(#[SensitiveParameter] string $accessToken, string $tokenType = 'Bearer'): self
    {
        return new self(type: CredentialType::OAuth2, value: $accessToken, tokenType: $tokenType);
    }

    /**
     * Never expose credential secrets in var_dump / exception dumps.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'type' => $this->type->value,
            'username' => $this->username,
            'tokenType' => $this->tokenType,
            'value' => $this->value !== null ? '[REDACTED]' : null,
            'password' => $this->password !== null ? '[REDACTED]' : null,
        ];
    }
}
