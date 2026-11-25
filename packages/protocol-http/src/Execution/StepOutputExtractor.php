<?php

declare(strict_types=1);

namespace Alama\Arazzo\Protocol\Http\Execution;

use Alama\Arazzo\Contracts\Interfaces\OutputExtractorInterface;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Expression;
use Alama\Arazzo\Contracts\Spec\Interfaces\WorkflowContextInterface;
use Alama\Arazzo\Contracts\Spec\Selector;
use Alama\Arazzo\Contracts\Spec\Step;
use Alama\Arazzo\Engine\Execution\TypeCaster;
use Alama\Arazzo\Evaluation\EvaluationEngineInterface;
use Alama\Arazzo\Expression\Data\EvaluationContext;
use Alama\Arazzo\Expression\Enum\ReferenceKind;
use Alama\Arazzo\Expression\Interfaces\ExpressionEngineInterface;
use Alama\Arazzo\Protocol\Http\Resolver\OpenApiOperationHandle;
use Alama\Arazzo\Protocol\Http\Resolver\OpenApiOperationResolver;
use cebe\openapi\spec\Reference;
use cebe\openapi\spec\Response;
use cebe\openapi\spec\Responses;
use cebe\openapi\spec\Schema;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;

/**
 * @internal stays out of the advertised contract; not part of the public API surface
 */
class StepOutputExtractor implements OutputExtractorInterface
{
    private readonly ExpressionEngineInterface $inspector;

    private readonly TypeCaster $typeCaster;

    public function __construct(
        private OpenApiOperationResolver $operationResolver,
        private EvaluationEngineInterface $evaluationEngine,
        ExpressionEngineInterface $inspector,
        private ?LoggerInterface $logger = null,
    ) {
        $this->inspector = $inspector;
        $this->typeCaster = new TypeCaster();
    }

    /**
     * @return array<string, mixed>
     */
    public function extractOutputs(Step $step, WorkflowContextInterface $context, ?ArazzoDocument $document = null): array
    {
        $responseBody = self::stepRecordValue($context, $step, 'response', 'body') ?? [];

        $outputs = [];
        foreach ($step->io->outputs as $outputName => $expression) {
            if ($expression instanceof Selector) {
                $outputs[$outputName] = $this->evaluationEngine->evaluateSelector($expression, $context, $step->stepId);

                continue;
            }

            if ($expression instanceof Expression) {
                $raw = trim($expression->raw);

                if (str_starts_with($raw, '$.')) {
                    $outputs[$outputName] = $this->evaluationEngine->jsonPath($raw, is_array($responseBody) ? $responseBody : []);

                    continue;
                }

                $value = $this->evaluationEngine->evaluate($expression, new EvaluationContext($context, $step->stepId, $document));
                $outputs[$outputName] = $this->castResponseOutput($step, $context, $document, $expression, $value);
            } else {
                $outputs[$outputName] = $expression;
            }
        }

        return $outputs;
    }

    private function castResponseOutput(
        Step $step,
        WorkflowContextInterface $context,
        ?ArazzoDocument $document,
        Expression $expression,
        mixed $value,
    ): mixed {
        if ($document === null) {
            return $value;
        }

        $pointer = $this->responseBodyPointer($expression);
        if ($pointer === null) {
            return $value;
        }

        $schema = $this->resolveResponseBodySchema($step, $context, $document);
        $leafSchema = $this->resolveSchemaAtPointer($schema, $pointer);

        return $this->castToSchemaType($value, $leafSchema);
    }

    private function responseBodyPointer(Expression $expression): ?string
    {
        $ref = $this->inspector->expressionReferences($expression->raw);
        if ($ref === null || $ref->jsonPointer === null) {
            return null;
        }

        $isStepResponseBody = $ref->kind === ReferenceKind::Step
            && $ref->part === 'response'
            && $ref->httpPart === 'body';

        return $isStepResponseBody ? $ref->jsonPointer : null;
    }

    private function resolveResponseBodySchema(
        Step $step,
        WorkflowContextInterface $context,
        ArazzoDocument $document,
    ): ?Schema {
        try {
            $responses = $this->resolveOperation($step, $document)->cebeOperation->responses;
        } catch (\RuntimeException) {
            return null;
        }

        if (!$responses instanceof Responses) {
            return null;
        }

        $statusCode = self::stringify(self::stepRecordValue($context, $step, 'response', 'statusCode'));
        $response = $responses->getResponse($statusCode) ?? $responses->getResponse('default');
        if (!$response instanceof Response) {
            return null;
        }

        $schema = $response->content['application/json']->schema ?? null;
        if ($schema instanceof Reference) {
            $schema = $schema->resolve();
        }

        return $schema instanceof Schema ? $schema : null;
    }

    private function resolveOperation(Step $step, ArazzoDocument $document): OpenApiOperationHandle
    {
        return $this->operationResolver->resolve($step, $document);
    }

    private function resolveSchemaAtPointer(?Schema $schema, string $pointer): ?Schema
    {
        if ($schema === null) {
            return null;
        }

        $segments = array_filter(explode('/', ltrim($pointer, '/')), static fn (string $segment): bool => $segment !== '');

        foreach ($segments as $segment) {
            $segment = str_replace(['~1', '~0'], ['/', '~'], $segment);

            if ($schema->type === 'array' && $schema->items instanceof Schema) {
                $schema = $schema->items;

                continue;
            }

            if (isset($schema->properties[$segment]) && $schema->properties[$segment] instanceof Schema) {
                $schema = $schema->properties[$segment];

                continue;
            }

            return null;
        }

        return $schema;
    }

    private function castToSchemaType(mixed $value, ?Schema $schema): mixed
    {
        if ($schema === null || ($schema->type ?? null) === null) {
            return $value;
        }

        try {
            return match ($schema->type) {
                'integer' => $this->typeCaster->asInteger($value),
                'number' => $this->typeCaster->asFloat($value),
                'string' => $this->typeCaster->asString($value),
                'boolean' => $this->typeCaster->asBoolean($value),
                'array' => $this->typeCaster->asArray($value),
                default => $value,
            };
        } catch (InvalidArgumentException $e) {
            $this->logger?->warning("Failed to cast value to schema type '{$schema->type}': {$e->getMessage()}");

            return $value;
        }
    }

    /**
     * Read a value out of a step's canonical record without trusting its shape.
     *
     * `WorkflowContextInterface::getSteps()` hands back `mixed` payloads — the
     * registry stores whatever the executor reported — so every hop is narrowed
     * rather than assumed to be an array.
     */
    private static function stepRecordValue(WorkflowContextInterface $context, Step $step, string ...$keys): mixed
    {
        $value = $context->getSteps()[$step->stepId] ?? null;

        foreach ($keys as $key) {
            if (!is_array($value)) {
                return null;
            }

            $value = $value[$key] ?? null;
        }

        return $value;
    }

    /**
     * Render a value as text without trusting its type: scalars as they are,
     * anything else as an empty string. A plain `(string)` cast raises on an
     * object and emits `Array` for an array.
     */
    private static function stringify(mixed $value): string
    {
        return match (true) {
            is_string($value) => $value,
            is_scalar($value) => (string) $value,
            default => '',
        };
    }
}
