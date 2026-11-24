<?php

declare(strict_types=1);

namespace Alama\Arazzo\Expression\Plugins\ValidationRule;

use Alama\Arazzo\Contracts\Attributes\AsValidationRulePlugin;
use Alama\Arazzo\Contracts\Interfaces\ValidationRuleInterface;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Enum\ExpressionType;
use Alama\Arazzo\Contracts\Spec\Enum\SpecVersion;
use Alama\Arazzo\Contracts\Spec\Parameter;
use Alama\Arazzo\Contracts\Spec\Reusable;
use Alama\Arazzo\Contracts\Spec\Selector;
use Alama\Arazzo\Contracts\Spec\Step;
use Alama\Arazzo\Contracts\Validation\ErrorCollector;
use Alama\Arazzo\Contracts\Validation\SymbolTable;
use Alama\Arazzo\Expression\Registries\SelectorPluginRegistry;

/**
 * @internal stays out of the advertised contract; not part of the public API surface
 */
#[AsValidationRulePlugin]
final class SelectorTypeSupportedRule implements ValidationRuleInterface
{
    /**
     * Versions the specification recognises for each selector type; anything
     * else is not a specification spelling at all.
     *
     * @var array<string, list<string>>
     */
    private const SPEC_VERSIONS = [
        'jsonpath' => ['rfc9535'],
        'xpath' => ['xpath-10', 'xpath-20', 'xpath-30', 'xpath-31'],
        'jsonpointer' => ['rfc6901'],
    ];

    /**
     * @param  list<string>|null  $supportedVersions  JSONPath versions the bound evaluator can apply (null = query SelectorPluginRegistry).
     */
    public function __construct(
        private readonly ?array $supportedVersions = null,
        private readonly ?SelectorPluginRegistry $selectorRegistry = null,
    ) {}

    public function check(ArazzoDocument $doc, SymbolTable $symbols, ErrorCollector $errors): void
    {
        if ($doc->specVersion === SpecVersion::V1_0) {
            return;
        }

        foreach ($doc->workflows as $wi => $wf) {
            $this->checkParameters($wf->parameters->all(), "/workflows/{$wi}/parameters", $errors);
            foreach ($wf->steps as $si => $step) {
                $this->checkStep($step, "/workflows/{$wi}/steps/{$si}", $errors);
            }
            $this->checkValues($wf->outputs->all(), "/workflows/{$wi}/outputs", $errors);
        }

        foreach ($doc->components->parameters as $name => $p) {
            if ($p->value instanceof Selector) {
                $this->validateSelector($p->value, $errors, "/components/parameters/{$name}/value");
            }
        }
    }

    /**
     * @param  array<int|string,Parameter|Reusable>  $parameters
     */
    private function checkParameters(array $parameters, string $basePath, ErrorCollector $errors): void
    {
        foreach ($parameters as $pi => $p) {
            if ($p instanceof Reusable) {
                continue;
            }

            if ($p->value instanceof Selector) {
                $this->validateSelector($p->value, $errors, "{$basePath}/{$pi}/value");
            }
        }
    }

    private function checkStep(Step $step, string $basePath, ErrorCollector $errors): void
    {
        $this->checkParameters($step->io->parameters, "{$basePath}/parameters", $errors);

        if ($step->io->requestBody !== null) {
            foreach ($step->io->requestBody->replacements as $ri => $r) {
                if ($r->value instanceof Selector) {
                    $this->validateSelector($r->value, $errors, "{$basePath}/requestBody/replacements/{$ri}/value");
                }
            }
        }

        $this->checkValues($step->io->outputs, "{$basePath}/outputs", $errors);
    }

    /**
     * @param  array<array-key,mixed>  $values
     */
    private function checkValues(array $values, string $basePath, ErrorCollector $errors): void
    {
        foreach ($values as $name => $value) {
            if ($value instanceof Selector) {
                $this->validateSelector($value, $errors, "{$basePath}/{$name}");
            }
        }
    }

    private function validateSelector(Selector $s, ErrorCollector $errors, string $pointer): void
    {
        if ($s->version === null) {
            return; // No pinned version = implementation default; allowed.
        }

        $specVersions = self::SPEC_VERSIONS[$s->type->value];

        if (!in_array($s->version, $specVersions, true)) {
            $errors->error(
                $this->code(),
                "Unsupported {$s->type->value} version '{$s->version}' at {$pointer}; expected one of: ".implode(', ', $specVersions),
                $pointer,
            );

            return;
        }

        // The injected list (or SelectorPluginRegistry's JSONPath plugin) is
        // the JSONPath versions the bound evaluator implements; other selector
        // types treat the spec list as implemented.
        $jsonPathVersions = $this->supportedVersions
            ?? ($this->selectorRegistry ?? SelectorPluginRegistry::create())->supportedVersions(ExpressionType::JsonPath);
        $implemented = $s->type->value === 'jsonpath' ? $jsonPathVersions : $specVersions;

        if (!in_array($s->version, $implemented, true)) {
            $errors->error(
                $this->code(),
                "{$s->type->value} version '{$s->version}' is recognised by the specification but not implemented by the bound evaluator (supports: ".implode(', ', $implemented).'). Pin a supported version, or omit the version to run as the engine default.',
                $pointer,
            );
        }
    }

    public function code(): string
    {
        return 'selector.type_supported';
    }
}
