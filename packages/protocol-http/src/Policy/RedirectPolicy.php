<?php

declare(strict_types=1);

namespace Alama\Arazzo\Protocol\Http\Policy;

use Alama\Arazzo\Protocol\Http\Exceptions\InvalidHttpPolicyException;
use Psr\Http\Message\UriInterface;

/**
 * Immutable value object governing outbound HTTP redirect following,
 * TLS downgrade prevention, and cross-origin boundary detection.
 */
final readonly class RedirectPolicy
{
    public const int DEFAULT_MAX_REDIRECTS = 10;

    public const bool DEFAULT_ALLOW_DOWNGRADE = false;

    public function __construct(
        public int $maxRedirects = self::DEFAULT_MAX_REDIRECTS,
        public bool $allowDowngrade = self::DEFAULT_ALLOW_DOWNGRADE,
    ) {
        if ($this->maxRedirects < 0) {
            throw InvalidHttpPolicyException::negativeMaxRedirects($this->maxRedirects);
        }
    }

    public static function default(): self
    {
        return new self();
    }

    public function exceedsLimit(int $hopCount): bool
    {
        return $hopCount > $this->maxRedirects;
    }

    public function isDowngrade(UriInterface $from, UriInterface $to): bool
    {
        return strtolower($from->getScheme()) === 'https'
            && strtolower($to->getScheme()) === 'http';
    }

    public function refusesDowngrade(UriInterface $from, UriInterface $to): bool
    {
        return !$this->allowDowngrade && $this->isDowngrade($from, $to);
    }

    public function isCrossOrigin(UriInterface $from, UriInterface $to): bool
    {
        $fromScheme = strtolower($from->getScheme());
        $toScheme = strtolower($to->getScheme());

        if ($fromScheme !== $toScheme) {
            return true;
        }

        if (strtolower($from->getHost()) !== strtolower($to->getHost())) {
            return true;
        }

        return self::effectivePort($fromScheme, $from->getPort())
            !== self::effectivePort($toScheme, $to->getPort());
    }

    public function withMaxRedirects(int $maxRedirects): self
    {
        return new self(maxRedirects: $maxRedirects, allowDowngrade: $this->allowDowngrade);
    }

    public function withAllowDowngrade(bool $allowDowngrade = true): self
    {
        return new self(maxRedirects: $this->maxRedirects, allowDowngrade: $allowDowngrade);
    }

    private static function effectivePort(string $scheme, ?int $port): ?int
    {
        if ($port !== null) {
            return $port;
        }

        return match ($scheme) {
            'http' => 80,
            'https' => 443,
            default => null,
        };
    }
}
