<?php

declare(strict_types=1);

namespace Alama\Arazzo\Expression\Internal;

use Alama\Arazzo\Expression\Ast\ExpressionAst;
use Alama\Arazzo\Expression\Ast\HttpMetaRef;
use Alama\Arazzo\Expression\Ast\InputPart;
use Alama\Arazzo\Expression\Ast\MessageRef;
use Alama\Arazzo\Expression\Ast\OutputPart;
use Alama\Arazzo\Expression\Ast\OutputRef;
use Alama\Arazzo\Expression\Ast\RequestPart;
use Alama\Arazzo\Expression\Ast\ResponsePart;
use Alama\Arazzo\Expression\Ast\StepPart;
use Alama\Arazzo\Expression\Ast\StepRef;
use Alama\Arazzo\Expression\Interfaces\EvaluationInputInterface;

/**
 * Resolves the references that read a step record: `$steps.*`, `$outputs.*`,
 * `$response.*`, `$request.*`, `$statusCode`, `$method`, `$url` and
 * `$message.*`.
 *
 * Split out of ExpressionEvaluator so the AST dispatch has one responsibility
 * per evaluator and each class stays under PHPMD's complexity thresholds (#260).
 *
 * @internal stays out of the advertised contract; not part of the public API surface
 */
final readonly class StepReferenceResolver
{
    public function __construct(private ValueReader $values) {}

    public function supports(ExpressionAst $ast): bool
    {
        return $ast instanceof StepRef
            || $ast instanceof MessageRef
            || $ast instanceof HttpMetaRef
            || $ast instanceof OutputRef;
    }

    public function resolve(ExpressionAst $ast, EvaluationInputInterface $context): mixed
    {
        return match (true) {
            $ast instanceof StepRef => $this->step($ast, $context),
            $ast instanceof MessageRef => $this->message($ast, $context),
            $ast instanceof HttpMetaRef => $this->httpMeta($ast, $context),
            $ast instanceof OutputRef => $this->output($ast, $context),
            default => null,
        };
    }

    /**
     * The workflow output the current step produced. A workflow-invocation
     * step records the invoked workflow's outputs on the step result, and the
     * step's own `outputs` map is evaluated against that same record.
     */
    private function output(OutputRef $ast, EvaluationInputInterface $context): mixed
    {
        $stepId = $context->getCurrentStepId();
        if ($stepId === null) {
            return null;
        }

        $outputs = $context->stepOutputs($stepId);
        $output = $outputs[$ast->name] ?? null;

        return $this->values->withPointer($output, $ast->jsonPointer);
    }

    private function httpMeta(HttpMetaRef $ast, EvaluationInputInterface $context): mixed
    {
        $stepId = $context->getCurrentStepId();
        if ($stepId === null) {
            return null;
        }

        return $context->httpMetaForStep($stepId, $ast->field);
    }

    private function step(StepRef $ast, EvaluationInputInterface $context): mixed
    {
        $targetStepId = $ast->stepId ?? $context->getCurrentStepId();
        if ($targetStepId === null) {
            return null;
        }

        return $this->part($ast->part, $context, $targetStepId);
    }

    private function part(StepPart $part, EvaluationInputInterface $context, string $stepId): mixed
    {
        return match (true) {
            $part instanceof RequestPart => $this->requestPart($part, $context, $stepId),
            $part instanceof ResponsePart => $this->responsePart($part, $context, $stepId),
            $part instanceof OutputPart => $this->outputsPart($part, $context, $stepId),
            $part instanceof InputPart => $context->stepInputs($stepId)[$part->name] ?? null,
            default => null,
        };
    }

    private function outputsPart(OutputPart $part, EvaluationInputInterface $context, string $stepId): mixed
    {
        $outputs = $context->stepOutputs($stepId);

        return $this->values->withPointer(
            $part->name === null ? $outputs : ($outputs[$part->name] ?? null),
            $part->jsonPointer,
        );
    }

    private function requestPart(RequestPart $part, EvaluationInputInterface $context, string $stepId): mixed
    {
        $request = $context->stepRequest($stepId);
        if ($request === null) {
            return null;
        }

        return match ($part->httpPart) {
            'header' => $this->headerAt($request, 'headers', $part->headerName),
            'query' => $this->headerAt($request, 'query', $part->headerName),
            'path' => $this->headerAt($request, 'path', $part->headerName),
            'body' => $this->values->body($request['body'] ?? null, $part->jsonPointer),
            default => null,
        };
    }

    private function responsePart(ResponsePart $part, EvaluationInputInterface $context, string $stepId): mixed
    {
        $response = $context->stepResponse($stepId);
        if ($response === null) {
            return null;
        }

        if ($part->httpPart === 'statusCode') {
            return $response['statusCode'] ?? null;
        }

        return match ($part->httpPart) {
            'header' => $this->headerAt($response, 'headers', $part->headerName),
            'body' => $this->values->body($response['body'] ?? null, $part->jsonPointer),
            default => null,
        };
    }

    private function message(MessageRef $ast, EvaluationInputInterface $context): mixed
    {
        $stepId = $context->getCurrentStepId();
        if ($stepId === null) {
            return null;
        }

        if ($ast->part === 'header') {
            return $context->stepResponseHeader($stepId, (string) $ast->name);
        }

        $body = $context->responseBodyForStep($stepId);

        return $this->values->withPointer($body, $ast->jsonPointer);
    }

    /** @param array<mixed, mixed> $data */
    private function headerAt(array $data, string $key, ?string $name): mixed
    {
        if ($name === null) {
            return null;
        }

        $value = $data[$key] ?? null;
        if (!is_array($value)) {
            return null;
        }

        return $value[$name] ?? null;
    }
}
