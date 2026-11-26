<?php

declare(strict_types=1);

namespace Alama\Arazzo\Document\Validator;

use Alama\Arazzo\Contracts\Interfaces\ValidationRuleInterface;
use Alama\Arazzo\Contracts\Validation\ValidatorPolicy;
use Alama\Arazzo\Document\Plugins\ValidationRule\DocUnknownFieldRule;
use Alama\Arazzo\Document\Plugins\ValidationRule\ReceiveStepSuccessCriteriaRule;
use Alama\Arazzo\Document\Registries\ValidationRuleRegistry;

/**
 * Immutable set of active validation rules and policy filters.
 *
 * @internal stays out of the advertised contract; not part of the public API surface
 */
final readonly class RuleSet
{
    /**
     * @param  list<ValidationRuleInterface>  $rules
     */
    public function __construct(
        public array $rules,
        public ValidatorPolicy $policy = new ValidatorPolicy(),
    ) {}

    /**
     * Builds the default RuleSet from {@see ValidationRuleRegistry::create()},
     * which auto-discovers all `#[AsValidationRulePlugin]` rules across installed
     * packages (`arazzo-document`, `arazzo-expression`, protocol packages, and
     * user applications).
     */
    public static function default(?ValidatorPolicy $policy = null): self
    {
        $policy ??= ValidatorPolicy::default();
        $rules = ValidationRuleRegistry::create()->all();

        if (!$policy->isStrict()) {
            $rules = self::applyStrictness($rules, false);
        }

        return new self($rules, $policy);
    }

    public function withRule(ValidationRuleInterface $rule): self
    {
        $filtered = array_values(array_filter(
            $this->rules,
            static fn (ValidationRuleInterface $existing): bool => $existing->code() !== $rule->code(),
        ));

        return new self([...$filtered, $rule], $this->policy);
    }

    public function withPolicy(ValidatorPolicy $policy): self
    {
        $rules = $this->rules;
        if ($policy->isStrict() !== $this->policy->isStrict()) {
            $rules = self::applyStrictness($rules, $policy->isStrict());
        }

        return new self($rules, $policy);
    }

    /**
     * @param  list<ValidationRuleInterface>  $rules
     * @return list<ValidationRuleInterface>
     */
    private static function applyStrictness(array $rules, bool $strict): array
    {
        return array_map(
            static fn (ValidationRuleInterface $rule): ValidationRuleInterface => match (true) {
                $rule instanceof DocUnknownFieldRule => new DocUnknownFieldRule($strict),
                $rule instanceof ReceiveStepSuccessCriteriaRule => new ReceiveStepSuccessCriteriaRule($strict),
                default => $rule,
            },
            $rules,
        );
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
            fn (ValidationRuleInterface $r): bool => !$this->policy->isDisabled($r->code()),
        ));
    }

    public function isStrict(): bool
    {
        return $this->policy->isStrict();
    }
}
