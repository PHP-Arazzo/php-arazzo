<?php

declare(strict_types=1);

namespace Alama\Arazzo\Document;

use Alama\Arazzo\Contracts\Data\KnownOperations;
use Alama\Arazzo\Contracts\Data\SourceResolutionCollection;
use Alama\Arazzo\Contracts\Interfaces\ValidationRuleInterface;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Validation\ValidationResult;
use Alama\Arazzo\Document\Parser\Exceptions\LoaderException;
use Alama\Arazzo\Document\Parser\Exceptions\ParserException;

/**
 * Entry-point seam for the document package.
 *
 * Exposes loading, static document validation and workflow input validation.
 */
interface DocumentInterface
{
    /**
     * Load and parse an Arazzo document from a YAML/JSON file path.
     *
     * @throws LoaderException
     * @throws ParserException
     */
    public function load(string $path): ArazzoDocument;

    /**
     * Load and parse an Arazzo document directly from a YAML string.
     *
     * @throws LoaderException
     * @throws ParserException
     */
    public function loadFromYaml(string $content, string $virtualPath = 'inline.yaml'): ArazzoDocument;

    /**
     * Load and parse an Arazzo document directly from a JSON string.
     *
     * @throws LoaderException
     * @throws ParserException
     */
    public function loadFromJson(string $content, string $virtualPath = 'inline.json'): ArazzoDocument;

    /**
     * Immutably register an extra or overriding validation rule for this Document instance.
     */
    public function withRule(ValidationRuleInterface $rule): static;

    /**
     * Static validation of a parsed document against spec rules.
     */
    public function validate(
        ArazzoDocument $document,
        ?KnownOperations $operations = null,
        ?SourceResolutionCollection $sourceResolutions = null,
    ): ValidationResult;

    /**
     * Validate supplied runtime inputs against a workflow's declared inputs
     * JSON Schema (2020-12).
     *
     * @param  array<string, mixed>  $inputs
     */
    public function validateInputs(ArazzoDocument $document, string $workflowId, array $inputs): ValidationResult;
}
