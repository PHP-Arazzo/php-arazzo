<?php

declare(strict_types=1);

namespace Alama\Arazzo\Runner\Sync;

use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Workflow;
use Alama\Arazzo\Contracts\Validation\ValidationResult;
use Alama\Arazzo\Document\DocumentInterface;
use Alama\Arazzo\Runner\Exceptions\PreflightFailureException;
use Alama\Arazzo\Runner\Preflight\Preflight;

/**
 * Runs input-schema and document preflight checks before a workflow executes
 * its first step.
 *
 * @internal stays out of the advertised contract; not part of the public API surface
 */
final readonly class WorkflowPreflightRunner
{
    public function __construct(
        private ?Preflight $preflight = null,
        private ?DocumentInterface $document = null,
    ) {}

    /**
     * @param  array<string, mixed>  $inputs
     * @return array<string, mixed>
     */
    public function validate(Workflow $workflow, ArazzoDocument $document, array $inputs): array
    {
        $errors = [];
        $warnings = [];

        $inputsSchema = $workflow->inputs;
        if (is_array($inputsSchema) && $inputsSchema !== [] && $this->document !== null) {
            $inputResult = $this->document->validateInputs($document, $workflow->workflowId, $inputs);
            if ($inputResult->errors !== []) {
                $errors = [...$errors, ...$inputResult->errors];
                $warnings = [...$warnings, ...$inputResult->warnings];
            } elseif ($inputResult->inputs !== []) {
                $inputs = $inputResult->inputs;
            }
        }

        if ($this->preflight !== null) {
            $preflightResult = $this->preflight->validate($document);
            $errors = [...$errors, ...$preflightResult->errors];
            $warnings = [...$warnings, ...$preflightResult->warnings];
        }

        if ($errors !== []) {
            $result = new ValidationResult($document, $errors, $warnings);
            throw new PreflightFailureException(
                'Preflight validation failed with '.count($result->errors).' error(s).',
                $result,
            );
        }

        return $inputs;
    }
}
