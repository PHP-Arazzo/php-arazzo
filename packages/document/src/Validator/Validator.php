<?php

declare(strict_types=1);

namespace Alama\Arazzo\Document\Validator;

use Alama\Arazzo\Contracts\Data\KnownOperations;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Validation\Error;
use Alama\Arazzo\Contracts\Validation\ErrorCollector;
use Alama\Arazzo\Contracts\Validation\Severity;
use Alama\Arazzo\Contracts\Validation\SymbolTable;
use Alama\Arazzo\Contracts\Validation\ValidationResult;
use JsonSchema\Constraints\Constraint;
use JsonSchema\SchemaStorage;
use JsonSchema\Validator as JsonSchemaValidator;

/**
 * @internal stays out of the advertised contract; not part of the public API surface
 */
final readonly class Validator
{
    /**
     * @param  KnownOperations|array<string,array<string,true>>|null  $resolvedOperations
     */
    public function __construct(
        private readonly RuleSet $rules,
        private readonly KnownOperations|array|null $resolvedOperations = null,
    ) {}

    /**
     * @param  KnownOperations|array<string,array<string,true>>|null  $resolvedOperations
     */
    public function validate(ArazzoDocument $doc, KnownOperations|array|null $resolvedOperations = null): ValidationResult
    {
        $raw = $resolvedOperations ?? $this->resolvedOperations;
        $ops = match (true) {
            $raw instanceof KnownOperations => $raw,
            is_array($raw) => new KnownOperations($raw),
            default => KnownOperations::none(),
        };

        $symbols = SymbolTable::build($doc, $ops, $this->rules->policy->isConformance());
        $collector = new ErrorCollector($this->rules->policy);

        foreach ($this->rules->activeRules() as $rule) {
            $rule->check($doc, $symbols, $collector);
        }

        return new ValidationResult($doc, $collector->errors(), $collector->warnings());
    }

    /**
     * Validates supplied runtime inputs against the workflow's declared
     * `inputs` JSON Schema (2020-12).
     *
     * @param  array<string, mixed>  $inputs
     */
    public function validateInputs(ArazzoDocument $document, string $workflowId, array $inputs): ValidationResult
    {
        $collector = new ErrorCollector();

        $workflow = $document->findWorkflow($workflowId);

        if ($workflow === null) {
            return new ValidationResult($document, [
                new Error('preflight.unknown_workflow', "Workflow '{$workflowId}' does not exist.", '/workflows/'.$workflowId),
            ], [], $inputs);
        }

        $schema = is_array($workflow->inputs ?? null) ? $workflow->inputs : null;

        if ($schema === null || $schema === []) {
            return new ValidationResult($document, [], [], $inputs);
        }

        $finalInputs = $inputs;
        $violations = $this->validateAgainstSchema($inputs, $schema, $finalInputs);

        foreach ($violations as $violation) {
            $pointer = '/workflows/'.$workflowId.'/inputs'
                .($violation['property'] !== ''
                    ? (string) preg_replace('/^\[([^\]]+)\]/', '/$1', $violation['property'])
                    : '');

            $collector->add(new Error(
                'preflight.inputs_schema',
                $violation['message'],
                $pointer,
                severity: Severity::Error,
            ));
        }

        return new ValidationResult($document, $collector->errors(), $collector->warnings(), $finalInputs);
    }

    /**
     * @param  array<string, mixed>  $inputs
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $populatedInputs
     * @return list<array{message: string, property: string}>
     */
    private function validateAgainstSchema(array $inputs, array $schema, array &$populatedInputs): array
    {
        $storage = new SchemaStorage();
        $schemaObject = json_decode((string) json_encode($schema), false);

        if (!$schemaObject instanceof \stdClass) {
            return [];
        }

        $schemaId = 'memory://inputs-schema';
        $storage->addSchema($schemaId, $schemaObject);

        $validator = new JsonSchemaValidator();
        $dataObject = json_decode((string) json_encode($inputs ?: new \stdClass()), false);

        $validator->validate(
            $dataObject,
            $schemaObject,
            Constraint::CHECK_MODE_APPLY_DEFAULTS,
        );

        if ($validator->isValid()) {
            $populated = json_decode((string) json_encode($dataObject), true);
            /** @var array<string, mixed> $populated */
            $populated = is_array($populated) ? $populated : [];
            $populatedInputs = $populated;

            return [];
        }

        $out = [];
        /** @var array{message: string, property?: string} $error */
        foreach ($validator->getErrors() as $error) {
            $out[] = [
                'message' => (string) $error['message'],
                'property' => is_string($error['property'] ?? null) ? $error['property'] : '',
            ];
        }

        return $out;
    }
}
