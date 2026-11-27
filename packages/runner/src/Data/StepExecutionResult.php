<?php

declare(strict_types=1);

namespace Alama\Arazzo\Runner\Data;

use Alama\Arazzo\Runner\Data\Enum\StepRole;
use Throwable;

/**
 * Immutable typed verdict for a single executed step.
 */
final readonly class StepExecutionResult
{
    /**
     * @param  array<array-key, mixed>  $outputs
     */
    public function __construct(
        public string $stepId,
        public bool $success,
        public array $outputs = [],
        public ?Throwable $error = null,
        public StepRole $role = StepRole::Standard,
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

    public function isTarget(): bool
    {
        return $this->role === StepRole::Target;
    }

    public function isDependency(): bool
    {
        return $this->role === StepRole::Dependency;
    }

    /**
     * @return array{
     *     stepId: string,
     *     success: bool,
     *     outputs: array<array-key, mixed>,
     *     error: ?string,
     *     role: string,
     *     dependency: bool,
     *     target: bool
     * }
     */
    public function toArray(): array
    {
        return [
            'stepId' => $this->stepId,
            'success' => $this->success,
            'outputs' => $this->outputs,
            'error' => $this->errorMessage(),
            'role' => $this->role->value,
            'dependency' => $this->isDependency(),
            'target' => $this->isTarget(),
        ];
    }
}
