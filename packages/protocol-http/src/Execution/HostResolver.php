<?php

declare(strict_types=1);

namespace Alama\Arazzo\Protocol\Http\Execution;

use Alama\Arazzo\Protocol\Http\Policy\NetworkPolicy;

/**
 * Concrete DNS host resolver used by {@see OutboundSafetyGuard} to verify
 * resolved IP addresses against {@see NetworkPolicy}.
 *
 * Intentionally non-final so test suites can substitute deterministic resolvers
 * without introducing a speculative single-implementation interface.
 *
 * @internal stays out of the advertised contract; not part of the public API surface
 */
class HostResolver
{
    /**
     * Resolves A and AAAA DNS records for a hostname.
     * Returns an empty list on lookup miss (fail-open contract).
     *
     * @return list<string>
     */
    public function resolveIps(string $host): array
    {
        $normalized = IpNetworkMatcher::normalizeHost($host);
        if ($normalized === '' || IpNetworkMatcher::isIpLiteral($normalized)) {
            return [];
        }

        $records = @dns_get_record($normalized, DNS_A | DNS_AAAA);
        if (!is_array($records)) {
            return [];
        }

        $ips = [];
        foreach ($records as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if (is_string($ip) && $ip !== '' && !in_array($ip, $ips, true)) {
                $ips[] = $ip;
            }
        }

        return $ips;
    }
}
