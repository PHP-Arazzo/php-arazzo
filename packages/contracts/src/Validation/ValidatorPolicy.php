<?php

declare(strict_types=1);

namespace Alama\Arazzo\Contracts\Validation;

/**
 * Immutable validator policy configuring per-rule severity overrides,
 * disabled rules, and strict validation mode.
 */
final readonly class ValidatorPolicy
{
    public const bool DEFAULT_STRICT = true;

    /**
     * @param  array<string, Severity>  $severities
     * @param  list<string>  $disabled
     */
    public function __construct(
        public array $severities = [],
        public array $disabled = [],
        public bool $strict = self::DEFAULT_STRICT,
    ) {}

    public static function default(): self
    {
        return new self();
    }

    public function withSeverity(string $ruleCode, Severity|string $severity): self
    {
        $resolved = is_string($severity) ? Severity::from($severity) : $severity;
        $severities = $this->severities;
        $severities[$ruleCode] = $resolved;

        return new self($severities, $this->disabled, $this->strict);
    }

    public function withDisabled(string ...$ruleCodes): self
    {
        $disabled = array_values(array_unique([...$this->disabled, ...$ruleCodes]));

        return new self($this->severities, $disabled, $this->strict);
    }

    public function withEnabled(string ...$ruleCodes): self
    {
        $disabled = array_values(array_filter(
            $this->disabled,
            static fn (string $code): bool => !in_array($code, $ruleCodes, true),
        ));

        return new self($this->severities, $disabled, $this->strict);
    }

    public function withStrict(bool $strict = true): self
    {
        return new self($this->severities, $this->disabled, $strict);
    }

    public function severityFor(string $ruleCode, Severity $default = Severity::Error): Severity
    {
        return $this->severities[$ruleCode] ?? $default;
    }

    public function isDisabled(string $ruleCode): bool
    {
        return in_array($ruleCode, $this->disabled, true);
    }

    public function isStrict(): bool
    {
        return $this->strict;
    }
}
