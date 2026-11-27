<?php

declare(strict_types=1);

namespace Alama\Arazzo\Protocol\Http\Execution;

use Alama\Arazzo\Protocol\Http\Policy\NetworkPolicy;

/**
 * Pure IPv4/IPv6 CIDR and host-pattern matcher supporting {@see NetworkPolicy}.
 *
 * @internal stays out of the advertised contract; not part of the public API surface
 */
final readonly class IpNetworkMatcher
{
    /** @var list<string> */
    private const array LINK_LOCAL_CIDRS = [
        '169.254.0.0/16',
        '0.0.0.0/8',
        'fe80::/10',
        '::/128',
    ];

    /** @var list<string> */
    private const array PRIVATE_CIDRS = [
        '127.0.0.0/8',
        '10.0.0.0/8',
        '172.16.0.0/12',
        '192.168.0.0/16',
        '100.64.0.0/10',
        '::1/128',
        'fc00::/7',
    ];

    public static function normalizeHost(string $host): string
    {
        $trimmed = strtolower(trim($host));
        if (str_starts_with($trimmed, '[') && str_ends_with($trimmed, ']')) {
            return substr($trimmed, 1, -1);
        }

        return rtrim($trimmed, '.');
    }

    public static function isIpLiteral(string $host): bool
    {
        return filter_var(self::normalizeHost($host), FILTER_VALIDATE_IP) !== false;
    }

    public static function isLinkLocal(string $ip): bool
    {
        return self::matchesAnyCidr(self::unwrapMappedIpv4($ip), self::LINK_LOCAL_CIDRS);
    }

    public static function isPrivateNetwork(string $ip): bool
    {
        return self::matchesAnyCidr(self::unwrapMappedIpv4($ip), self::PRIVATE_CIDRS);
    }

    /**
     * @param  list<string>  $patterns
     */
    public static function matchesAnyPattern(string $hostOrIp, array $patterns): bool
    {
        $normalized = self::normalizeHost($hostOrIp);
        $isIp = filter_var($normalized, FILTER_VALIDATE_IP) !== false;
        $unwrapped = $isIp ? self::unwrapMappedIpv4($normalized) : $normalized;

        foreach ($patterns as $pattern) {
            if (self::matchesSinglePattern($normalized, $unwrapped, $isIp, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private static function matchesSinglePattern(string $normalized, string $unwrapped, bool $isIp, string $pattern): bool
    {
        $clean = self::normalizeHost($pattern);
        if ($clean === '') {
            return false;
        }

        if (str_contains($clean, '/')) {
            return $isIp && self::matchesCidr($unwrapped, $clean);
        }

        if (str_starts_with($clean, '*.')) {
            $suffix = substr($clean, 1);

            return str_ends_with($normalized, $suffix) && strlen($normalized) > strlen($suffix);
        }

        return $normalized === $clean || ($isIp && $unwrapped === self::unwrapMappedIpv4($clean));
    }

    /**
     * @param  list<string>  $cidrs
     */
    private static function matchesAnyCidr(string $ip, array $cidrs): bool
    {
        foreach ($cidrs as $cidr) {
            if (self::matchesCidr($ip, $cidr)) {
                return true;
            }
        }

        return false;
    }

    public static function matchesCidr(string $ip, string $cidr): bool
    {
        $parts = explode('/', $cidr, 2);
        if (count($parts) !== 2 || !ctype_digit($parts[1])) {
            return false;
        }

        $ipBin = @inet_pton($ip);
        $netBin = @inet_pton($parts[0]);
        if ($ipBin === false || $netBin === false || strlen($ipBin) !== strlen($netBin)) {
            return false;
        }

        $prefix = (int) $parts[1];
        $maxBits = strlen($ipBin) * 8;
        if ($prefix < 0 || $prefix > $maxBits) {
            return false;
        }

        return self::binaryPrefixMatches($ipBin, $netBin, $prefix);
    }

    private static function binaryPrefixMatches(string $ipBin, string $netBin, int $prefix): bool
    {
        $fullBytes = intdiv($prefix, 8);
        if ($fullBytes > 0 && substr($ipBin, 0, $fullBytes) !== substr($netBin, 0, $fullBytes)) {
            return false;
        }

        $remainingBits = $prefix % 8;
        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainingBits)) & 0xFF;

        return (ord($ipBin[$fullBytes]) & $mask) === (ord($netBin[$fullBytes]) & $mask);
    }

    private static function unwrapMappedIpv4(string $ip): string
    {
        $normalized = self::normalizeHost($ip);
        $bin = @inet_pton($normalized);
        if ($bin === false || strlen($bin) !== 16) {
            return $normalized;
        }

        // RFC 4291 IPv4-mapped IPv6 prefix ::ffff:0:0/96
        if (substr($bin, 0, 12) !== "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff") {
            return $normalized;
        }

        $ipv4 = @inet_ntop(substr($bin, 12, 4));

        return $ipv4 !== false ? $ipv4 : $normalized;
    }
}
