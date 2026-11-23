<?php

declare(strict_types=1);

namespace Alama\Arazzo\Document\Registries;

use Alama\Arazzo\Contracts\Attributes\AsValidationRule;
use Alama\Arazzo\Contracts\Discovery\PluginDiscovery;
use Alama\Arazzo\Contracts\Interfaces\ValidationRuleInterface;
use ReflectionClass;

/**
 * Concrete singleton registry of Arazzo document validation rules.
 *
 * Auto-discovers all `#[AsValidationRule]` classes declared across installed
 * packages' `composer.json` `extra.arazzo.plugins` manifests (structural rules
 * from `arazzo-document`, expression rules from `arazzo-expression`, protocol
 * rules from `arazzo-protocol-soap` / `arazzo-protocol-rpc`, and custom user
 * rules).
 *
 * Rules are keyed by {@see ValidationRuleInterface::code()} and ordered by
 * priority (higher runs earlier; registration order breaks ties).
 */
final class ValidationRuleRegistry
{
    private static ?self $instance = null;

    /** @var array<string, array{rule: ValidationRuleInterface, priority: int}> */
    private array $rules = [];

    private function __construct()
    {
        foreach (PluginDiscovery::instantiate(AsValidationRule::class) as $rule) {
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

    public function register(ValidationRuleInterface $rule, ?int $priority = null): void
    {
        if ($priority === null) {
            $attrs = (new ReflectionClass($rule))->getAttributes(AsValidationRule::class);
            if ($attrs !== []) {
                /** @var AsValidationRule $attr */
                $attr = $attrs[0]->newInstance();
                $priority = $attr->priority;
            }
        }

        $this->rules[$rule->code()] = [
            'rule' => $rule,
            'priority' => $priority ?? 0,
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
     * @return list<ValidationRuleInterface>
     */
    public function all(): array
    {
        $entries = array_values($this->rules);
        usort(
            $entries,
            static fn (array $a, array $b): int => $b['priority'] <=> $a['priority'],
        );

        return array_map(
            static fn (array $entry): ValidationRuleInterface => $entry['rule'],
            $entries,
        );
    }
}
