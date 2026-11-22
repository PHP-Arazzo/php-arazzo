<?php

declare(strict_types=1);

namespace Alama\Arazzo\Document;

use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Document\Parser\DecodePolicy;
use Alama\Arazzo\Document\Parser\Loader;
use Alama\Arazzo\Document\Parser\Parser;
use Alama\Arazzo\Document\Validator\Data\ValidationResult;
use Alama\Arazzo\Document\Validator\RuleSet;
use Alama\Arazzo\Document\Validator\Validator;
use Alama\Arazzo\Expression\ExpressionEngine;

/**
 * Public façade of the *document* package.
 *
 * Pure model work – no I/O, no cebe, no Guzzle.
 */
final readonly class Document implements DocumentInterface
{
    private function __construct(
        private Loader $loader,
        private Parser $parser,
        private Validator $validator,
    ) {}

    public static function create(): self
    {
        $engine = new ExpressionEngine();

        return new self(
            loader: Loader::new(),
            parser: new Parser(),
            validator: new Validator(RuleSet::default($engine)),
        );
    }

    public function withDecodePolicy(DecodePolicy $policy): self
    {
        return new self(
            loader: Loader::defineDecodePolicy($policy),
            parser: $this->parser,
            validator: $this->validator,
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
