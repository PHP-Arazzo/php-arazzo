<?php

declare(strict_types=1);

namespace Alama\Arazzo\Protocol\Http\Policy;

use Alama\Arazzo\Protocol\Http\Exceptions\InvalidHttpPolicyException;
use Alama\Arazzo\Protocol\Http\Execution\IpNetworkMatcher;

/**
 * Immutable SSRF target-filtering value object governing allowed URI schemes,
 * host allow/deny lists, link-local/metadata blocking, and private-network blocking.
 */
final readonly class NetworkPolicy
{
    /** @var list<string> */
    public const array DEFAULT_ALLOWED_SCHEMES = ['http', 'https'];

    public const bool DEFAULT_BLOCK_LINK_LOCAL = true;

    public const bool DEFAULT_BLOCK_PRIVATE_NETWORKS = false;

    /** @var list<string> */
    public array $allowedSchemes;

    /** @var list<string> */
    public array $allowedHosts;

    /** @var list<string> */
    public array $deniedHosts;

    /**
     * @param  list<string>  $allowedSchemes
     * @param  list<string>  $allowedHosts
     * @param  list<string>  $deniedHosts
     */
    public function __construct(
        array $allowedSchemes = self::DEFAULT_ALLOWED_SCHEMES,
        array $allowedHosts = [],
        array $deniedHosts = [],
        public bool $blockLinkLocal = self::DEFAULT_BLOCK_LINK_LOCAL,
        public bool $blockPrivateNetworks = self::DEFAULT_BLOCK_PRIVATE_NETWORKS,
    ) {
        $normalizedSchemes = self::normalizeValues($allowedSchemes);
        if ($normalizedSchemes === []) {
            throw InvalidHttpPolicyException::emptyAllowedSchemes();
        }

        $this->allowedSchemes = $normalizedSchemes;
        $this->allowedHosts = self::normalizeValues($allowedHosts, asHost: true);
        $this->deniedHosts = self::normalizeValues($deniedHosts, asHost: true);
    }

    public static function default(): self
    {
        return new self();
    }

    public function allowsScheme(string $scheme): bool
    {
        return in_array(strtolower($scheme), $this->allowedSchemes, true);
    }

    public function allowsHost(string $host): bool
    {
        $normalized = IpNetworkMatcher::normalizeHost($host);
        if ($normalized === '') {
            return false;
        }

        if (IpNetworkMatcher::matchesAnyPattern($normalized, $this->deniedHosts)) {
            return false;
        }

        if ($this->allowedHosts !== [] && !IpNetworkMatcher::matchesAnyPattern($normalized, $this->allowedHosts)) {
            return false;
        }

        if (IpNetworkMatcher::isIpLiteral($normalized)) {
            return $this->allowsIp($normalized);
        }

        return true;
    }

    public function allowsIp(string $ip): bool
    {
        $normalized = IpNetworkMatcher::normalizeHost($ip);
        if (!IpNetworkMatcher::isIpLiteral($normalized)) {
            return false;
        }

        if (IpNetworkMatcher::matchesAnyPattern($normalized, $this->deniedHosts)) {
            return false;
        }

        if ($this->blockLinkLocal && IpNetworkMatcher::isLinkLocal($normalized)) {
            return false;
        }

        if ($this->blockPrivateNetworks && IpNetworkMatcher::isPrivateNetwork($normalized)) {
            return false;
        }

        return true;
    }

    /**
     * @param  list<string>  $allowedSchemes
     */
    public function withAllowedSchemes(array $allowedSchemes): self
    {
        return new self(
            allowedSchemes: $allowedSchemes,
            allowedHosts: $this->allowedHosts,
            deniedHosts: $this->deniedHosts,
            blockLinkLocal: $this->blockLinkLocal,
            blockPrivateNetworks: $this->blockPrivateNetworks,
        );
    }

    /**
     * @param  list<string>  $allowedHosts
     */
    public function withAllowedHosts(array $allowedHosts): self
    {
        return new self(
            allowedSchemes: $this->allowedSchemes,
            allowedHosts: $allowedHosts,
            deniedHosts: $this->deniedHosts,
            blockLinkLocal: $this->blockLinkLocal,
            blockPrivateNetworks: $this->blockPrivateNetworks,
        );
    }

    /**
     * @param  list<string>  $deniedHosts
     */
    public function withDeniedHosts(array $deniedHosts): self
    {
        return new self(
            allowedSchemes: $this->allowedSchemes,
            allowedHosts: $this->allowedHosts,
            deniedHosts: $deniedHosts,
            blockLinkLocal: $this->blockLinkLocal,
            blockPrivateNetworks: $this->blockPrivateNetworks,
        );
    }

    public function withBlockLinkLocal(bool $blockLinkLocal = true): self
    {
        return new self(
            allowedSchemes: $this->allowedSchemes,
            allowedHosts: $this->allowedHosts,
            deniedHosts: $this->deniedHosts,
            blockLinkLocal: $blockLinkLocal,
            blockPrivateNetworks: $this->blockPrivateNetworks,
        );
    }

    public function withBlockPrivateNetworks(bool $blockPrivateNetworks = true): self
    {
        return new self(
            allowedSchemes: $this->allowedSchemes,
            allowedHosts: $this->allowedHosts,
            deniedHosts: $this->deniedHosts,
            blockLinkLocal: $this->blockLinkLocal,
            blockPrivateNetworks: $blockPrivateNetworks,
        );
    }

    /**
     * @param  list<string>  $items
     * @return list<string>
     */
    private static function normalizeValues(array $items, bool $asHost = false): array
    {
        $normalized = [];
        foreach ($items as $item) {
            $clean = $asHost ? IpNetworkMatcher::normalizeHost($item) : strtolower(trim($item));
            if ($clean !== '' && !in_array($clean, $normalized, true)) {
                $normalized[] = $clean;
            }
        }

        return $normalized;
    }
}
