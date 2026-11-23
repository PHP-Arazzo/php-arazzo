<?php

declare(strict_types=1);

namespace Alama\Arazzo\Document\Validator;

use Alama\Arazzo\Contracts\Interfaces\ValidationRuleInterface;
use Alama\Arazzo\Document\Registries\ValidationRuleRegistry;

/**
 * Immutable set of active validation rules and strictness/disable filters.
 *
 * @internal stays out of the advertised contract; not part of the public API surface
 */
final readonly class RuleSet
{
    /**
     * @param  list<ValidationRuleInterface>  $rules
     * @param  list<string>  $disabled
     */
    public function __construct(
        public array $rules,
        public array $disabled = [],
        public bool $strict = true,
    ) {}

    /**
     * Builds the default RuleSet from {@see ValidationRuleRegistry::create()},
     * which auto-discovers all `#[AsValidationRulePlugin]` rules across installed
     * packages (`arazzo-document`, `arazzo-expression`, protocol packages, and
     * user applications).
     *
     * @param  list<string>  $disabled
     */
    public static function default(
        array $disabled = [],
        bool $strict = true,
    ): self {
        return new self(
            ValidationRuleRegistry::create()->all(),
            $disabled,
            $strict,
        );
    }

    public function withRule(ValidationRuleInterface $rule): self
    {
        $filtered = array_values(array_filter(
            $this->rules,
            static fn (ValidationRuleInterface $existing): bool => $existing->code() !== $rule->code(),
        ));

        return new self([...$filtered, $rule], $this->disabled, $this->strict);
    }

    /** @return list<ValidationRuleInterface> */
    public function rules(): array
    {
        return $this->rules;
    }

    /** @return list<ValidationRuleInterface> */
    public function activeRules(): array
    {
        return array_values(array_filter(
            $this->rules,
            fn (ValidationRuleInterface $r): bool => !in_array($r->code(), $this->disabled, true),
        ));
    }

    public function isStrict(): bool
    {
        return $this->strict;
    }
}
