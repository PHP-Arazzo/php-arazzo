<?php

declare(strict_types=1);

namespace Alama\Arazzo\Protocol\Http\Execution;

use Alama\Arazzo\Protocol\Http\Exceptions\HttpPolicyViolationException;
use Alama\Arazzo\Protocol\Http\Policy\OutboundSafetyPolicy;
use Alama\Arazzo\Protocol\Http\Policy\RedirectPolicy;
use Alama\Arazzo\Protocol\Http\Policy\TransferPolicy;
use GuzzleHttp\ClientInterface as GuzzleClientInterface;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Dispatches outbound HTTP requests with per-hop SSRF validation, explicit
 * RFC 9110 redirect loop enforcement, cross-origin credential stripping,
 * connect timeout forwarding, and bounded response streaming.
 *
 * @internal stays out of the advertised contract; not part of the public API surface
 */
final readonly class SafeHttpDispatcher
{
    /** @var list<int> */
    private const array REDIRECT_STATUS_CODES = [301, 302, 303, 307, 308];

    /** @var list<string> */
    private const array SENSITIVE_HEADERS = [
        'Authorization',
        'Proxy-Authorization',
        'Cookie',
    ];

    public function __construct(
        private ClientInterface $httpClient,
        private OutboundSafetyGuard $safetyGuard = new OutboundSafetyGuard(),
    ) {}

    /**
     * @param  list<string>  $credentialHeaders  Extra source-injected credential header names to strip cross-origin
     */
    public function dispatch(
        RequestInterface $request,
        OutboundSafetyPolicy $safety,
        ?float $timeoutSeconds = null,
        array $credentialHeaders = [],
    ): ResponseInterface {
        $currentRequest = $request;
        $hops = 0;

        while (true) {
            $this->safetyGuard->assertTargetAllowed($currentRequest->getUri(), $safety->network);
            $response = $this->sendSingleHop($currentRequest, $safety->transfer, $timeoutSeconds);

            $location = trim($response->getHeaderLine('Location'));
            $statusCode = $response->getStatusCode();

            if (!in_array($statusCode, self::REDIRECT_STATUS_CODES, true) || $location === '') {
                return $this->safetyGuard->enforceResponseCap($response, $currentRequest->getUri(), $safety->transfer);
            }

            $response->getBody()->close();
            $hops++;

            if ($safety->redirects->exceedsLimit($hops)) {
                throw HttpPolicyViolationException::redirectLimitExceeded(
                    (string) $currentRequest->getUri(),
                    $safety->redirects->maxRedirects,
                );
            }

            $currentRequest = $this->buildRedirectRequest(
                $currentRequest,
                $statusCode,
                $location,
                $safety->redirects,
                $credentialHeaders,
            );
        }
    }

    private function sendSingleHop(
        RequestInterface $request,
        TransferPolicy $transfer,
        ?float $timeoutSeconds,
    ): ResponseInterface {
        if ($this->httpClient instanceof GuzzleClientInterface) {
            $headers = array_map(
                static fn (array $values): array => $values === [] ? [''] : array_values(array_map(strval(...), $values)),
                $request->getHeaders(),
            );

            $options = [
                'headers' => $headers,
                'body' => (string) $request->getBody(),
                'allow_redirects' => false,
                'stream' => true,
                'http_errors' => false,
                'connect_timeout' => $transfer->connectTimeout,
            ];

            if ($timeoutSeconds !== null) {
                $options['timeout'] = $timeoutSeconds;
            }

            return $this->httpClient->request(
                $request->getMethod(),
                (string) $request->getUri(),
                $options,
            );
        }

        return $this->httpClient->sendRequest($request);
    }

    /**
     * @param  list<string>  $credentialHeaders
     */
    private function buildRedirectRequest(
        RequestInterface $currentRequest,
        int $statusCode,
        string $location,
        RedirectPolicy $redirects,
        array $credentialHeaders,
    ): RequestInterface {
        $fromUri = $currentRequest->getUri();
        $targetUri = UriResolver::resolve($fromUri, new Uri($location));

        if ($redirects->refusesDowngrade($fromUri, $targetUri)) {
            throw HttpPolicyViolationException::downgradeRefused((string) $fromUri, (string) $targetUri);
        }

        $nextRequest = $currentRequest->withUri($targetUri);
        $nextRequest = $this->rewriteMethodForRedirect($nextRequest, $statusCode);

        if ($redirects->isCrossOrigin($fromUri, $targetUri)) {
            $nextRequest = $this->stripSensitiveHeaders($nextRequest, $credentialHeaders);
        }

        return $nextRequest;
    }

    private function rewriteMethodForRedirect(RequestInterface $request, int $statusCode): RequestInterface
    {
        $method = strtoupper($request->getMethod());
        $shouldRewriteToGet = ($statusCode === 303 && $method !== 'GET' && $method !== 'HEAD')
            || (in_array($statusCode, [301, 302], true) && $method === 'POST');

        if (!$shouldRewriteToGet) {
            $body = $request->getBody();
            if ($body->isSeekable()) {
                $body->rewind();
            }

            return $request;
        }

        return $request
            ->withMethod('GET')
            ->withoutHeader('Content-Length')
            ->withoutHeader('Content-Type')
            ->withBody(Utils::streamFor(''));
    }

    /**
     * @param  list<string>  $credentialHeaders
     */
    private function stripSensitiveHeaders(RequestInterface $request, array $credentialHeaders): RequestInterface
    {
        foreach (array_merge(self::SENSITIVE_HEADERS, $credentialHeaders) as $headerName) {
            $request = $request->withoutHeader($headerName);
        }

        return $request;
    }
}
