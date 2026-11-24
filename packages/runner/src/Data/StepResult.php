<?php

declare(strict_types=1);

namespace Alama\Arazzo\Runner\Data;

use Throwable;

/**
 * Immutable typed verdict for a single executed step.
 */
final readonly class StepResult
{
    /**
     * @param  array<array-key, mixed>  $outputs
     */
    public function __construct(
        public string $stepId,
        public bool $success,
        public array $outputs = [],
        public ?Throwable $error = null,
    ) {}

    public function isSucceeded(): bool
    {
        return $this->success;
    }

    public function isFailed(): bool
    {
        return !$this->success;
    }

    public function hasError(): bool
    {
        return $this->error !== null;
    }

    public function errorMessage(): ?string
    {
        return $this->error?->getMessage();
    }

    public function output(string $name): mixed
    {
        return $this->outputs[$name] ?? null;
    }

    public function hasOutput(string $name): bool
    {
        return array_key_exists($name, $this->outputs);
    }

    /**
     * @return array{stepId: string, success: bool, outputs: array<array-key, mixed>, error: ?string}
     */
    public function toArray(): array
    {
        return [
            'stepId' => $this->stepId,
            'success' => $this->success,
            'outputs' => $this->outputs,
            'error' => $this->errorMessage(),
        ];
    }
}
