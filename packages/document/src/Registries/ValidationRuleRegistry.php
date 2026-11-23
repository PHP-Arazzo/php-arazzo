<?php

declare(strict_types=1);

namespace Alama\Arazzo\Document\Registries;

use Alama\Arazzo\Contracts\Attributes\AsValidationRulePlugin;
use Alama\Arazzo\Contracts\Discovery\PluginDiscovery;
use Alama\Arazzo\Contracts\Interfaces\ValidationRuleInterface;
use Alama\Arazzo\Contracts\Validation\ValidationPhase;
use ReflectionClass;

/**
 * Concrete singleton registry of Arazzo document validation rules.
 *
 * Auto-discovers all `#[AsValidationRulePlugin]` classes declared across installed
 * packages' `composer.json` `extra.arazzo.plugins` directories.
 *
 * Rules are keyed by {@see ValidationRuleInterface::code()} (last-registered
 * wins) and grouped by {@see ValidationPhase} (`Schema` runs before `Semantic`).
 */
final class ValidationRuleRegistry
{
    private static ?self $instance = null;

    /** @var array<string, array{rule: ValidationRuleInterface, phase: ValidationPhase}> */
    private array $rules = [];

    private function __construct()
    {
        foreach (PluginDiscovery::instantiate(AsValidationRulePlugin::class) as $rule) {
            if ($rule instanceof ValidationRuleInterface) {
                $this->register($rule);
            }
        }
    }

    public static function create(): self
    {
        return self::$instance ??= new self();
    }

    public static function reset(): void
    {
        self::$instance = null;
    }

    public function register(ValidationRuleInterface $rule, ?ValidationPhase $phase = null): void
    {
        if ($phase === null) {
            $attrs = (new ReflectionClass($rule))->getAttributes(AsValidationRulePlugin::class);
            if ($attrs !== []) {
                /** @var AsValidationRulePlugin $attr */
                $attr = $attrs[0]->newInstance();
                $phase = $attr->phase;
            }
        }

        // Last-registered wins for a given rule code.
        $this->rules[$rule->code()] = [
            'rule' => $rule,
            'phase' => $phase ?? ValidationPhase::Semantic,
        ];
    }

    public function get(string $code): ?ValidationRuleInterface
    {
        return $this->rules[$code]['rule'] ?? null;
    }

    public function has(string $code): bool
    {
        return isset($this->rules[$code]);
    }

    /**
     * Schema-phase rules run before Semantic-phase rules.
     *
     * @return list<ValidationRuleInterface>
     */
    public function all(): array
    {
        $schema = [];
        $semantic = [];

        foreach ($this->rules as $entry) {
            if ($entry['phase'] === ValidationPhase::Schema) {
                $schema[] = $entry['rule'];
            } else {
                $semantic[] = $entry['rule'];
            }
        }

        return [...$schema, ...$semantic];
    }
}
