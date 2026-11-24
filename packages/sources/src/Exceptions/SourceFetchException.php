<?php

declare(strict_types=1);

namespace Alama\Arazzo\Sources\Exceptions;

use Throwable;

class SourceFetchException extends SourceResolutionException
{
    final public function __construct(string $message = '', int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    public static function schemeDisallowed(string $scheme): static
    {
        return new static("Scheme '{$scheme}' is disallowed by FetchPolicy.");
    }

    public static function resolutionFailed(string $sourceName, Throwable $previous): static
    {
        return new static("Failed to resolve source '{$sourceName}': {$previous->getMessage()}", 0, $previous);
    }

    public static function fileReadFailed(string $path): static
    {
        return new static("Failed to read local file: {$path}");
    }

    public static function httpConnectionFailed(string $url, Throwable $previous): static
    {
        return new static("HTTP connection failed for {$url}: {$previous->getMessage()}", 0, $previous);
    }

    public static function httpErrorStatus(string $url, int $statusCode): static
    {
        return new static("HTTP request failed for {$url}: {$statusCode}");
    }

    public static function responseTooLarge(string $url, int $maxBytes): static
    {
        return new static("HTTP response for {$url} exceeded maximum size of {$maxBytes} bytes.");
    }

    public static function relativeUrlRequiresHttpBase(string $urlOrPath): static
    {
        return new static("Cannot resolve relative URL '{$urlOrPath}' without an HTTP or HTTPS basePath.");
    }

    public static function noFetcherForScheme(string $scheme): static
    {
        return new static("No fetcher configured for scheme '{$scheme}'.");
    }
}
