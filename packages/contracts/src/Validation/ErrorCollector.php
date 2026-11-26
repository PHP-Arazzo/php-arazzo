<?php

declare(strict_types=1);

namespace Alama\Arazzo\Contracts\Validation;

final class ErrorCollector
{
    /** @var list<Error> */
    private array $errors = [];

    /** @var list<Warning> */
    private array $warnings = [];

    public function __construct(
        private readonly ?ValidatorPolicy $policy = null,
    ) {}

    public function error(string $code, string $message, string $path, ?int $line = null): void
    {
        $severity = $this->policy?->severityFor($code, Severity::Error) ?? Severity::Error;
        if ($severity === Severity::Warning) {
            $this->warnings[] = new Warning($code, $message, $path, $line);

            return;
        }

        $this->errors[] = new Error($code, $message, $path, $line);
    }

    public function warning(string $code, string $message, string $path, ?int $line = null): void
    {
        $severity = $this->policy?->severityFor($code, Severity::Warning) ?? Severity::Warning;
        if ($severity === Severity::Error) {
            $this->errors[] = new Error($code, $message, $path, $line);

            return;
        }

        $this->warnings[] = new Warning($code, $message, $path, $line);
    }

    public function add(Error|Warning $diagnostic): void
    {
        $defaultSeverity = $diagnostic instanceof Error ? Severity::Error : Severity::Warning;
        $severity = $this->policy?->severityFor($diagnostic->code, $defaultSeverity) ?? $defaultSeverity;

        if ($severity === Severity::Warning) {
            $this->warnings[] = $diagnostic instanceof Warning
                ? $diagnostic
                : new Warning($diagnostic->code, $diagnostic->message, $diagnostic->path, $diagnostic->line);

            return;
        }

        $this->errors[] = $diagnostic instanceof Error
            ? $diagnostic
            : new Error($diagnostic->code, $diagnostic->message, $diagnostic->path, $diagnostic->line);
    }

    /** @return list<Error> */
    public function errors(): array
    {
        return $this->errors;
    }

    /** @return list<Warning> */
    public function warnings(): array
    {
        return $this->warnings;
    }
}
