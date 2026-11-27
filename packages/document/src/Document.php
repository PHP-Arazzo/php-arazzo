<?php

declare(strict_types=1);

namespace Alama\Arazzo\Document;

use Alama\Arazzo\Contracts\Data\KnownOperations;
use Alama\Arazzo\Contracts\Data\SourceResolutionCollection;
use Alama\Arazzo\Contracts\Interfaces\ValidationRuleInterface;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Validation\ValidationResult;
use Alama\Arazzo\Contracts\Validation\ValidatorPolicy;
use Alama\Arazzo\Document\Parser\DecodePolicy;
use Alama\Arazzo\Document\Parser\Loader;
use Alama\Arazzo\Document\Parser\Parser;
use Alama\Arazzo\Document\Validator\RuleSet;
use Alama\Arazzo\Document\Validator\Validator;

/**
 * Public façade of the *document* package.
 *
 * Pure model work – no I/O, no cebe, no Guzzle, no expression engine coupling.
 * Validation rules are auto-discovered via {@see Registries\ValidationRuleRegistry}.
 */
final readonly class Document implements DocumentInterface
{
    private Loader $loader;

    private Parser $parser;

    private ?Validator $validator;

    /** @var array<string, ValidationRuleInterface> */
    private array $extraRules;

    private ValidatorPolicy $validatorPolicy;

    /**
     * Public autowirable constructor for enterprise DI containers.
     *
     * @param  array<string, ValidationRuleInterface>  $extraRules
     */
    public function __construct(
        ?Loader $loader = null,
        ?Parser $parser = null,
        ?Validator $validator = null,
        array $extraRules = [],
        ?ValidatorPolicy $validatorPolicy = null,
    ) {
        $this->loader = $loader ?? Loader::new();
        $this->parser = $parser ?? new Parser();
        $this->validator = $validator;
        $this->extraRules = $extraRules;
        $this->validatorPolicy = $validatorPolicy ?? ValidatorPolicy::default();
    }

    public static function create(): self
    {
        return new self();
    }

    public function withDecodePolicy(DecodePolicy $policy): self
    {
        return new self(
            loader: Loader::defineDecodePolicy($policy),
            parser: $this->parser,
            validator: $this->validator,
            extraRules: $this->extraRules,
            validatorPolicy: $this->validatorPolicy,
        );
    }

    public function withRule(ValidationRuleInterface $rule): static
    {
        $extraRules = $this->extraRules;
        $extraRules[$rule->code()] = $rule;

        return new self(
            loader: $this->loader,
            parser: $this->parser,
            validator: $this->validator,
            extraRules: $extraRules,
            validatorPolicy: $this->validatorPolicy,
        );
    }

    public function withValidatorPolicy(ValidatorPolicy $policy): self
    {
        return new self(
            loader: $this->loader,
            parser: $this->parser,
            validator: $this->validator,
            extraRules: $this->extraRules,
            validatorPolicy: $policy,
        );
    }

    public function load(string $path): ArazzoDocument
    {
        return $this->parser->parse($this->loader->load($path));
    }

    public function loadFromYaml(string $content, string $virtualPath = 'inline.yaml'): ArazzoDocument
    {
        return $this->parser->parse($this->loader->loadFromYaml($content, $virtualPath));
    }

    public function loadFromJson(string $content, string $virtualPath = 'inline.json'): ArazzoDocument
    {
        return $this->parser->parse($this->loader->loadFromJson($content, $virtualPath));
    }

    public function validate(
        ArazzoDocument $document,
        ?KnownOperations $operations = null,
        ?SourceResolutionCollection $sourceResolutions = null,
    ): ValidationResult {
        return $this->resolveValidator()->validate($document, $operations, $sourceResolutions);
    }

    public function validateInputs(ArazzoDocument $document, string $workflowId, array $inputs): ValidationResult
    {
        return $this->resolveValidator()->validateInputs($document, $workflowId, $inputs);
    }

    private function resolveValidator(): Validator
    {
        if ($this->validator !== null) {
            return $this->validator;
        }

        $ruleSet = RuleSet::default($this->validatorPolicy);
        foreach ($this->extraRules as $rule) {
            $ruleSet = $ruleSet->withRule($rule);
        }

        return new Validator($ruleSet);
    }
}
