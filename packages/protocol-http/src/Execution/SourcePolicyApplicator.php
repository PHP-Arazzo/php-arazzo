<?php

declare(strict_types=1);

namespace Alama\Arazzo\Protocol\Http\Execution;

use Alama\Arazzo\Contracts\Policy\Credential;
use Alama\Arazzo\Contracts\Policy\CredentialType;
use Alama\Arazzo\Contracts\Policy\SourcePolicy;
use Alama\Arazzo\Protocol\Http\Resolver\OpenApiOperationHandle;
use cebe\openapi\spec\OpenApi;
use cebe\openapi\spec\Reference;
use cebe\openapi\spec\SecurityScheme;
use Psr\Http\Message\RequestInterface;

/**
 * Applies a source-addressed {@see SourcePolicy} to an OpenAPI HTTP request:
 * server URL / template variable substitution, default static headers, and
 * strict OpenAPI security-scheme credential injection.
 *
 * Credentials are ONLY injected for security schemes required by the resolved
 * operation (or root OpenAPI `security` when the operation omits `security`).
 * Operations declaring `security: []` receive no credentials.
 *
 * @internal stays out of the advertised contract; not part of the public API surface
 */
final readonly class SourcePolicyApplicator
{
    public function resolveBaseUrl(OpenApi $openApi, ?SourcePolicy $policy): string
    {
        $template = $policy?->server();
        $variables = [];

        if ($template === null) {
            if (!$openApi->servers) {
                return '';
            }

            $server = $openApi->servers[0];
            $template = $server->url;

            foreach ($server->variables as $varName => $varSpec) {
                $variables[$varName] = (string) $varSpec->default;
            }
        }

        if ($policy !== null) {
            foreach ($policy->serverVariables() as $varName => $value) {
                $variables[$varName] = $value;
            }
        }

        foreach ($variables as $varName => $value) {
            $template = str_replace('{'.$varName.'}', $value, $template);
        }

        return rtrim($template, '/');
    }

    public function applyToRequest(
        RequestInterface $request,
        OpenApiOperationHandle $operation,
        ?SourcePolicy $policy,
    ): RequestInterface {
        if ($policy === null) {
            return $request;
        }

        foreach ($policy->headers as $name => $value) {
            if (!$request->hasHeader($name)) {
                $request = $request->withHeader($name, $value);
            }
        }

        return $this->applySecurity($request, $operation, $policy);
    }

    private function applySecurity(
        RequestInterface $request,
        OpenApiOperationHandle $operation,
        SourcePolicy $policy,
    ): RequestInterface {
        $requirements = $this->effectiveSecurityRequirements($operation);
        if ($requirements === []) {
            return $request;
        }

        $schemes = $operation->openApi->components->securitySchemes ?? [];

        foreach ($requirements as $requirement) {
            $matched = $this->matchRequirement($requirement, $schemes, $policy);
            if ($matched === null) {
                continue;
            }

            foreach ($matched as [$scheme, $credential]) {
                $request = $this->applyCredential($request, $scheme, $credential);
            }

            return $request;
        }

        return $request;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function effectiveSecurityRequirements(OpenApiOperationHandle $operation): array
    {
        $opRaw = $operation->cebeOperation->getRawSpecData();
        $apiRaw = $operation->openApi->getRawSpecData();

        $raw = array_key_exists('security', $opRaw)
            ? $opRaw['security']
            : ($apiRaw['security'] ?? null);

        if (!is_array($raw)) {
            return [];
        }

        $normalized = [];
        foreach ($raw as $entry) {
            if (is_array($entry)) {
                /** @var array<string, mixed> $entry */
                $normalized[] = $entry;
            }
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $requirement
     * @param  array<int|string, SecurityScheme|Reference>  $schemes
     * @return list<array{0: SecurityScheme, 1: Credential}>|null
     */
    private function matchRequirement(array $requirement, array $schemes, SourcePolicy $policy): ?array
    {
        $matched = [];

        foreach (array_keys($requirement) as $schemeName) {
            $scheme = $schemes[$schemeName] ?? null;
            $credential = $policy->credential($schemeName);

            if (!$scheme instanceof SecurityScheme || $credential === null) {
                return null;
            }

            $matched[] = [$scheme, $credential];
        }

        return $matched;
    }

    private function applyCredential(
        RequestInterface $request,
        SecurityScheme $scheme,
        Credential $credential,
    ): RequestInterface {
        return match ($credential->type) {
            CredentialType::ApiKey => $this->applyApiKey($request, $scheme, $credential),
            CredentialType::Bearer, CredentialType::OAuth2 => $request->withHeader(
                'Authorization',
                ($credential->tokenType ?? 'Bearer').' '.($credential->value ?? ''),
            ),
            CredentialType::Basic => $request->withHeader(
                'Authorization',
                'Basic '.base64_encode(($credential->username ?? '').':'.($credential->password ?? '')),
            ),
        };
    }

    private function applyApiKey(
        RequestInterface $request,
        SecurityScheme $scheme,
        Credential $credential,
    ): RequestInterface {
        $location = strtolower((string) $scheme->in);
        $paramName = (string) $scheme->name;
        $secret = $credential->value ?? '';

        if ($location === 'header') {
            return $request->withHeader($paramName, $secret);
        }

        if ($location === 'query') {
            $uri = $request->getUri();
            $existing = $uri->getQuery();
            $queryPart = rawurlencode($paramName).'='.rawurlencode($secret);
            $nextQuery = $existing === '' ? $queryPart : $existing.'&'.$queryPart;

            return $request->withUri($uri->withQuery($nextQuery));
        }

        if ($location === 'cookie') {
            $existing = $request->getHeaderLine('Cookie');
            $cookiePart = $paramName.'='.$secret;
            $nextCookie = $existing === '' ? $cookiePart : $existing.'; '.$cookiePart;

            return $request->withHeader('Cookie', $nextCookie);
        }

        return $request;
    }
}
