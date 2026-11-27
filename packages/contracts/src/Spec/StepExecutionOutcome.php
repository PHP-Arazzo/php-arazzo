<?php

declare(strict_types=1);

namespace Alama\Arazzo\Contracts\Spec;

use Throwable;

final readonly class StepExecutionOutcome
{
    public ?string $rawBody;

    public ?string $contentType;

    /**
     * @param  array<string, mixed>  $outputs
     * @param  array<string, mixed>  $responseBody
     * @param  array<string, mixed>  $inputs
     * @param  array<string, mixed>|null  $request
     * @param  array<string, string>  $responseHeaders
     * @param  array{rawBody?: ?string, contentType?: ?string}  $meta
     */
    private function __construct(
        public bool $suspended,
        public ?int $statusCode = null,
        public array $outputs = [],
        public array $responseBody = [],
        public array $inputs = [],
        public ?array $request = null,
        public array $responseHeaders = [],
        public ?string $failureCategory = null,
        public ?Throwable $exception = null,
        array $meta = [],
    ) {
        $this->rawBody = $meta['rawBody'] ?? null;
        $this->contentType = $meta['contentType'] ?? null;
    }

    /**
     * @param  array<string, mixed>  $outputs
     * @param  array<string, mixed>  $responseBody
     * @param  array<string, mixed>  $inputs
     * @param  array<string, mixed>|null  $request
     * @param  array<string, string>  $responseHeaders
     */
    public static function resolved(
        int $statusCode,
        array $outputs,
        array $responseBody,
        array $inputs = [],
        ?array $request = null,
        array $responseHeaders = [],
        ?string $rawBody = null,
        ?string $contentType = null,
        ?string $failureCategory = null,
    ): self {
        return new self(
            false,
            $statusCode,
            $outputs,
            $responseBody,
            $inputs,
            $request,
            $responseHeaders,
            $failureCategory,
            null,
            ['rawBody' => $rawBody, 'contentType' => $contentType],
        );
    }

    /**
     * @param  array<string, mixed>|null  $request
     * @param  array<string, string>  $responseHeaders
     * @param  array{rawBody?: ?string, contentType?: ?string}  $meta
     */
    public static function failed(
        Throwable $exception,
        string $failureCategory = 'transport',
        ?array $request = null,
        array $responseHeaders = [],
        array $meta = [],
    ): self {
        return new self(
            suspended: false,
            statusCode: null,
            outputs: [],
            responseBody: ['error' => $exception->getMessage()],
            inputs: [],
            request: $request,
            responseHeaders: $responseHeaders,
            failureCategory: $failureCategory,
            exception: $exception,
            meta: $meta,
        );
    }

    public static function suspended(): self
    {
        return new self(true);
    }

    public function isFailed(): bool
    {
        return $this->failureCategory !== null || $this->exception !== null;
    }

    public function isResolved(): bool
    {
        return !$this->suspended && !$this->isFailed();
    }
}
