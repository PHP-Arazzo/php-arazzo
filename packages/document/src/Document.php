<?php

declare(strict_types=1);

namespace Alama\Arazzo\Document;

use Alama\Arazzo\Contracts\Data\KnownOperations;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Document\Parser\DecodePolicy;
use Alama\Arazzo\Document\Parser\Loader;
use Alama\Arazzo\Document\Parser\Parser;
use Alama\Arazzo\Document\Validator\Data\ValidationResult;
use Alama\Arazzo\Document\Validator\RuleSet;
use Alama\Arazzo\Document\Validator\Validator;
use Alama\Arazzo\Expression\ExpressionEngine;
use Alama\Arazzo\Expression\Interfaces\ExpressionEngineInterface;

/**
 * Public façade of the *document* package.
 *
 * Pure model work – no I/O, no cebe, no Guzzle.
 */
final readonly class Document implements DocumentInterface
{
    private Loader $loader;

    private Parser $parser;

    private Validator $validator;

    /**
     * Public autowirable constructor for enterprise DI containers.
     */
    public function __construct(
        ?ExpressionEngineInterface $expressionEngine = null,
        ?DecodePolicy $decodePolicy = null,
        ?Loader $loader = null,
        ?Parser $parser = null,
        ?Validator $validator = null,
    ) {
        $engine = $expressionEngine ?? new ExpressionEngine();
        $this->loader = $loader ?? ($decodePolicy !== null ? Loader::defineDecodePolicy($decodePolicy) : Loader::new());
        $this->parser = $parser ?? new Parser();
        $this->validator = $validator ?? new Validator(RuleSet::default($engine));
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
        );
    }

    public function withExpressionEngine(ExpressionEngineInterface $engine): self
    {
        return new self(
            loader: $this->loader,
            parser: $this->parser,
            validator: new Validator(RuleSet::default($engine)),
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

    public function validate(ArazzoDocument $document, ?KnownOperations $operations = null): ValidationResult
    {
        return $this->validator->validate($document, $operations);
    }

    public function validateInputs(ArazzoDocument $document, string $workflowId, array $inputs): ValidationResult
    {
        return $this->validator->validateInputs($document, $workflowId, $inputs);
    }
}
