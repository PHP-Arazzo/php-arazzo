<?php

declare(strict_types=1);

namespace Alama\Arazzo\Protocol\Http\Execution;

use Alama\Arazzo\Protocol\Http\Exceptions\HttpPolicyViolationException;
use Alama\Arazzo\Protocol\Http\Policy\NetworkPolicy;
use Alama\Arazzo\Protocol\Http\Policy\TransferPolicy;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;

/**
 * Enforces outbound target scheme/host/DNS SSRF checks and bounded streaming
 * response reads against {@see NetworkPolicy} and {@see TransferPolicy}.
 *
 * @internal stays out of the advertised contract; not part of the public API surface
 */
final readonly class OutboundSafetyGuard
{
    private const int STREAM_CHUNK_BYTES = 8192;

    public function __construct(
        private HostResolver $hostResolver = new HostResolver(),
    ) {}

    public function assertTargetAllowed(UriInterface $uri, NetworkPolicy $network): void
    {
        $url = (string) $uri;
        [$scheme, $host] = self::resolveAuthority($uri, $url);

        // OpenAPI 3.x permits omitting `servers` (defaulting to relative path `/...`).
        if ($scheme === '' && $host === '') {
            return;
        }

        if (!$network->allowsScheme($scheme)) {
            throw HttpPolicyViolationException::schemeRefused($url, $scheme);
        }

        if (!$network->allowsHost($host)) {
            throw HttpPolicyViolationException::deniedHost($url, $host, 'host is not permitted');
        }

        if (IpNetworkMatcher::isIpLiteral($host)) {
            return;
        }

        foreach ($this->hostResolver->resolveIps($host) as $resolvedIp) {
            if (!$network->allowsIp($resolvedIp)) {
                throw HttpPolicyViolationException::deniedHost($url, $host, "resolved IP '{$resolvedIp}' is blocked");
            }
        }
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function resolveAuthority(UriInterface $uri, string $url): array
    {
        $scheme = strtolower($uri->getScheme());
        $host = $uri->getHost();

        if ($scheme !== '' || $host !== '' || $url === '') {
            return [$scheme, $host];
        }

        $parsedScheme = parse_url($url, PHP_URL_SCHEME);
        $parsedHost = parse_url($url, PHP_URL_HOST);

        return [
            is_string($parsedScheme) ? strtolower($parsedScheme) : '',
            is_string($parsedHost) ? $parsedHost : '',
        ];
    }

    public function enforceResponseCap(
        ResponseInterface $response,
        UriInterface $uri,
        TransferPolicy $transfer,
    ): ResponseInterface {
        $url = (string) $uri;
        $contentLength = trim($response->getHeaderLine('Content-Length'));

        if ($contentLength !== '' && ctype_digit($contentLength) && $transfer->exceedsResponseCap((int) $contentLength)) {
            $response->getBody()->close();

            throw HttpPolicyViolationException::responseTooLarge($url, $transfer->maxResponseBytes);
        }

        $body = $response->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }

        $buffer = '';
        $bytesRead = 0;

        while (!$body->eof()) {
            $remaining = ($transfer->maxResponseBytes - $bytesRead) + 1;
            $chunk = $body->read(min(self::STREAM_CHUNK_BYTES, max(1, $remaining)));
            if ($chunk === '') {
                break;
            }

            $bytesRead += strlen($chunk);
            if ($transfer->exceedsResponseCap($bytesRead)) {
                $body->close();

                throw HttpPolicyViolationException::responseTooLarge($url, $transfer->maxResponseBytes);
            }

            $buffer .= $chunk;
        }

        return $response->withBody(Utils::streamFor($buffer));
    }
}
