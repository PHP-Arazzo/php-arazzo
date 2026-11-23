<?php

declare(strict_types=1);

namespace Alama\Arazzo\Expression\Validation\Rules;

use Alama\Arazzo\Contracts\Attributes\AsValidationRule;
use Alama\Arazzo\Contracts\Interfaces\ValidationRuleInterface;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Enum\CriterionType;
use Alama\Arazzo\Contracts\Spec\Enum\ExpressionType;
use Alama\Arazzo\Contracts\Validation\ErrorCollector;
use Alama\Arazzo\Contracts\Validation\SymbolTable;
use Alama\Arazzo\Expression\ExpressionEngine;

/**
 * Guard the Expression Type Object `version` for `xpath` criteria.
 *
 * The specification recognises `xpath-10`, `xpath-20`, `xpath-30` and
 * `xpath-31`, and defines `xpath-31` as the default. This engine implements
 * XPath 1.0 only (DOMXPath), so a recognised version it cannot actually apply
 * is refused here rather than accepted by validation and then failed at
 * evaluation time. The supported set is injected from the bound evaluator
 * (`RuleSet::default()`), which keeps the validator and the runtime in step.
 *
 * A criterion that omits `version` runs as `xpath-10`: the deliberate,
 * documented deviation from the specification default, because 3.1 semantics
 * are not available.
 *
 * @internal stays out of the advertised contract; not part of the public API surface
 */
#[AsValidationRule]
final class SuccessCriteriaVersionSupportedRule implements ValidationRuleInterface
{
    /**
     * Versions the Expression Type Object recognises; anything else is not a
     * specification spelling at all.
     *
     * @var list<string>
     */
    private const SPEC_VERSIONS = ['xpath-10', 'xpath-20', 'xpath-30', 'xpath-31'];

    /**
     * @param  list<string>|null  $supportedVersions  Versions the bound evaluator can apply (null = query active ExpressionEngine).
     */
    public function __construct(
        private readonly ?array $supportedVersions = null,
    ) {}

    public function check(ArazzoDocument $doc, SymbolTable $symbols, ErrorCollector $errors): void
    {
        $implemented = $this->supportedVersions
            ?? ExpressionEngine::create()->supportedSelectorVersions(ExpressionType::XPath);

        foreach ($doc->workflows as $i => $w) {
            foreach ($w->steps as $j => $s) {
                foreach ($s->io->successCriteria as $k => $c) {
                    if ($c->type !== CriterionType::XPath || $c->version === null) {
                        continue;
                    }

                    $path = "/workflows/{$i}/steps/{$j}/successCriteria/{$k}/type/version";

                    if (!in_array($c->version, self::SPEC_VERSIONS, true)) {
                        $errors->error(
                            $this->code(),
                            "criterion type 'xpath' version '{$c->version}' is not a recognised XPath version (allowed: ".implode(', ', self::SPEC_VERSIONS).').',
                            $path,
                        );

                        continue;
                    }

                    if (!in_array($c->version, $implemented, true)) {
                        $errors->error(
                            $this->code(),
                            "criterion type 'xpath' version '{$c->version}' is recognised by the specification but not implemented by this evaluator (supports: ".implode(', ', $implemented).'). Pin a supported version, or omit the version to run as the engine default.',
                            $path,
                        );
                    }
                }
            }
        }
    }

    public function code(): string
    {
        return 'step.success_criteria_version_supported';
    }
}
