<?php

declare(strict_types=1);

namespace Alama\Arazzo\Protocol\Http\Exceptions;

use Alama\Arazzo\Engine\Exceptions\EngineException;
use Alama\Arazzo\Protocol\Http\Policy\OutboundSafetyPolicy;

/**
 * Thrown when an outbound HTTP request or response violates configured
 * {@see OutboundSafetyPolicy} bounds.
 */
final class HttpPolicyViolationException extends EngineException
{
    public const string CODE_REDIRECT_LIMIT = 'transport.redirect_limit';

    public const string CODE_DOWNGRADE_REFUSED = 'transport.downgrade_refused';

    public const string CODE_RESPONSE_TOO_LARGE = 'transport.response_too_large';

    public const string CODE_SCHEME_REFUSED = 'transport.scheme_refused';

    public const string CODE_DENIED_HOST = 'transport.denied_host';

    public static function redirectLimitExceeded(string $url, int $maxRedirects): self
    {
        return new self(
            "Outbound HTTP request to '{$url}' exceeded the maximum redirect limit of {$maxRedirects}.",
            '/',
            self::CODE_REDIRECT_LIMIT,
        );
    }

    public static function downgradeRefused(string $fromUrl, string $toUrl): self
    {
        return new self(
            "Outbound HTTP redirect downgraded protocol from '{$fromUrl}' to '{$toUrl}'.",
            '/',
            self::CODE_DOWNGRADE_REFUSED,
        );
    }

    public static function responseTooLarge(string $url, int $maxResponseBytes): self
    {
        return new self(
            "Outbound HTTP response from '{$url}' exceeded the {$maxResponseBytes}-byte response limit.",
            '/',
            self::CODE_RESPONSE_TOO_LARGE,
        );
    }

    public static function schemeRefused(string $url, string $scheme): self
    {
        return new self(
            "Outbound HTTP target '{$url}' uses disallowed URI scheme '{$scheme}'.",
            '/',
            self::CODE_SCHEME_REFUSED,
        );
    }

    public static function deniedHost(string $url, string $host, string $reason): self
    {
        return new self(
            "Outbound HTTP target '{$url}' (host '{$host}') was refused by network policy: {$reason}.",
            '/',
            self::CODE_DENIED_HOST,
        );
    }

    public function getErrorCode(): string
    {
        return $this->codeId;
    }
}
