<?php

declare(strict_types=1);

namespace Alama\Arazzo\Runner\Preflight;

use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Selector;
use Alama\Arazzo\Contracts\Spec\Step;
use Alama\Arazzo\Contracts\Spec\Workflow;
use Alama\Arazzo\Contracts\Validation\Error;
use Alama\Arazzo\Contracts\Validation\Severity;
use Alama\Arazzo\Contracts\Validation\ValidationResult;

final readonly class Preflight
{
    /**
     * @param  list<string>  $supportedXPath
     */
    public function __construct(
        private OperationResolvers $resolvers,
        private array $supportedXPath = ['xpath-10'],
    ) {}

    public function validate(ArazzoDocument $document): ValidationResult
    {
        $errors = [];

        foreach ($document->workflows as $workflow) {
            foreach ($workflow->steps as $step) {
                $this->checkStep($document, $workflow, $step, $errors);
            }
        }

        return new ValidationResult($document, $errors, []);
    }

    /**
     * @param  list<Error>  $errors
     */
    private function checkStep(ArazzoDocument $document, Workflow $workflow, Step $step, array &$errors): void
    {
        $base = '/workflows/'.$workflow->workflowId.'/steps/'.$step->stepId;

        // 1. If step targets an operation, check resolution through registered resolvers
        if ($step->target->operationId !== null || $step->target->operationPath !== null) {
            if (!$this->resolvers->supports($step, $document)) {
                $errors[] = new Error(
                    'preflight.no_resolver',
                    "No registered operation resolver supports step '{$step->stepId}'.",
                    $base.'/operationPath',
                    severity: Severity::Error,
                );

                return;
            }

            try {
                $this->resolvers->resolve($step, $document);
            } catch (\Throwable $e) {
                $errors[] = new Error(
                    'preflight.operation_unresolvable',
                    "Step '{$step->stepId}' operation resolution failed: {$e->getMessage()}",
                    $base.'/operationPath',
                    severity: Severity::Error,
                );

                return;
            }
        }

        // 2. Validate selector XPath versions
        foreach ($this->selectorsOf($step) as $selector) {
            $version = $selector->version ?? null;
            if ($version !== null && $selector->type->value === 'xpath') {
                if (!in_array($version, $this->supportedXPath, true)) {
                    $errors[] = new Error(
                        'preflight.unsupported_selector_version',
                        "Selector requests XPath version '{$version}'; evaluator supports: ".implode(', ', $this->supportedXPath).'.',
                        $base.'/parameters',
                        severity: Severity::Error,
                    );
                }
            }
        }
    }

    /** @return list<Selector> */
    private function selectorsOf(Step $step): array
    {
        $selectors = [];
        foreach ($step->io->parameters as $parameter) {
            if ($parameter->value instanceof Selector) {
                $selectors[] = $parameter->value;
            }
        }
        foreach ($step->io->outputs as $value) {
            if ($value instanceof Selector) {
                $selectors[] = $value;
            }
        }

        return $selectors;
    }
}
