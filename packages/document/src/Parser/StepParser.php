<?php

declare(strict_types=1);

namespace Alama\Arazzo\Document\Parser;

use Alama\Arazzo\Contracts\Spec\Enum\RpcProtocol;
use Alama\Arazzo\Contracts\Spec\Expression;
use Alama\Arazzo\Contracts\Spec\Step;
use Alama\Arazzo\Contracts\Spec\StepFlow;
use Alama\Arazzo\Contracts\Spec\StepIo;
use Alama\Arazzo\Contracts\Spec\StepTarget;
use Alama\Arazzo\Document\Parser\Exceptions\ParserException;

/**
 * Parses Arazzo Step objects (target, flow, and I/O sections).
 *
 * @internal stays out of the advertised contract; not part of the public API surface
 */
final class StepParser extends FieldParser
{
    public function __construct(
        private readonly ValueParser $values,
        private readonly ActionParser $actions,
        private readonly ExtensionNodeParser $extensions,
        private readonly RequestBodyParser $requestBodies,
    ) {}

    public function parseStep(mixed $node, ParseContext $ctx): Step
    {
        $obj = $this->requireObjectMap($node, $ctx);

        return new Step(
            stepId: $this->requireString($obj, 'stepId', $ctx),
            description: $this->optionalString($obj, 'description', $ctx),
            target: $this->parseStepTarget($obj, $ctx),
            flow: $this->parseStepFlow($obj, $ctx),
            io: $this->parseStepIo($obj, $ctx),
        );
    }

    /**
     * @param  array<string,mixed>  $obj
     */
    private function parseStepTarget(array $obj, ParseContext $ctx): StepTarget
    {
        $action = $this->optionalString($obj, 'action', $ctx);
        $channelPath = $this->optionalString($obj, 'channelPath', $ctx);
        $correlationIdRaw = $this->optionalString($obj, 'correlationId', $ctx);
        $correlationId = $correlationIdRaw !== null ? new Expression($correlationIdRaw) : null;
        $operationId = $this->optionalString($obj, 'operationId', $ctx);
        $operationPath = $this->optionalString($obj, 'operationPath', $ctx);
        $workflowId = $this->optionalString($obj, 'workflowId', $ctx);
        $operationName = $this->optionalString($obj, 'operationName', $ctx);
        $rpcMethod = $this->optionalString($obj, 'rpcMethod', $ctx);
        $rpcProtocol = $this->parseRpcProtocol($obj, $ctx);
        $graphqlOperation = $this->parseOptionalNode($obj, 'graphqlOperation', $ctx, $this->extensions->parseGraphQlOperation(...));
        $interaction = $this->parseOptionalNode($obj, 'interaction', $ctx, $this->extensions->parseInteraction(...));

        return match (true) {
            $workflowId !== null => StepTarget::workflow($workflowId),
            $action !== null && $channelPath !== null => StepTarget::async($action, $channelPath, $correlationId),
            $operationId !== null || $operationPath !== null => new StepTarget(
                operationId: $operationId,
                operationPath: $operationPath,
                workflowId: $workflowId,
                action: $action,
                channelPath: $channelPath,
                correlationId: $correlationId,
                operationName: $operationName,
                rpcMethod: $rpcMethod,
                rpcProtocol: $rpcProtocol,
                graphqlOperation: $graphqlOperation,
                interaction: $interaction,
            ),
            default => new StepTarget(
                operationName: $operationName,
                rpcMethod: $rpcMethod,
                rpcProtocol: $rpcProtocol,
                graphqlOperation: $graphqlOperation,
                interaction: $interaction,
            ),
        };
    }

    /** @param array<string,mixed> $obj */
    private function parseRpcProtocol(array $obj, ParseContext $ctx): ?RpcProtocol
    {
        $raw = $this->optionalString($obj, 'rpcProtocol', $ctx);
        if ($raw === null) {
            return null;
        }

        $protocol = RpcProtocol::tryFrom($raw);
        if ($protocol === null) {
            throw ParserException::invalidEnum($ctx->push('rpcProtocol'), 'grpc|grpc-web|twirp|connect', $raw);
        }

        return $protocol;
    }

    /**
     * @template T
     *
     * @param  array<string,mixed>  $obj
     * @param  callable(mixed, ParseContext): T  $parse
     * @return T|null
     */
    private function parseOptionalNode(array $obj, string $key, ParseContext $ctx, callable $parse): mixed
    {
        if (!array_key_exists($key, $obj) || $obj[$key] === null) {
            return null;
        }

        return $parse($obj[$key], $ctx->push($key));
    }

    /**
     * @param  array<string,mixed>  $obj
     */
    private function parseStepFlow(array $obj, ParseContext $ctx): StepFlow
    {
        $dependsOn = [];
        if (($d = $this->optionalList($obj, 'dependsOn', $ctx)) !== null) {
            foreach ($d as $i => $item) {
                if (!is_string($item)) {
                    throw ParserException::wrongType(
                        $ctx->push('dependsOn')->push((string) $i), 'string', $item,
                    );
                }
                $dependsOn[] = $item;
            }
        }

        $onSuccess = [];
        if (($o = $this->optionalList($obj, 'onSuccess', $ctx)) !== null) {
            foreach ($o as $i => $item) {
                $onSuccess[] = $this->actions->parseSuccessAction($item, $ctx->push('onSuccess')->push($i));
            }
        }

        $onFailure = [];
        if (($o = $this->optionalList($obj, 'onFailure', $ctx)) !== null) {
            foreach ($o as $i => $item) {
                $onFailure[] = $this->actions->parseFailureAction($item, $ctx->push('onFailure')->push($i));
            }
        }

        return new StepFlow(
            dependsOn: $dependsOn,
            timeout: $this->optionalInt($obj, 'timeout', $ctx),
            onSuccess: $onSuccess,
            onFailure: $onFailure,
            strictValidation: $this->optionalBool($obj, 'x-strict-validation', $ctx),
            idempotencyKey: $this->optionalBool($obj, 'x-idempotency-key', $ctx),
            idempotencyHeader: $this->optionalString($obj, 'x-idempotency-header', $ctx),
        );
    }

    /**
     * @param  array<string,mixed>  $obj
     */
    private function parseStepIo(array $obj, ParseContext $ctx): StepIo
    {
        $parameters = [];
        if (($p = $this->optionalList($obj, 'parameters', $ctx)) !== null) {
            foreach ($p as $i => $item) {
                $parameters[] = $this->values->parseParameter($item, $ctx->push('parameters')->push($i));
            }
        }

        $requestBody = null;
        if (array_key_exists('requestBody', $obj) && $obj['requestBody'] !== null) {
            $requestBody = $this->requestBodies->parseRequestBody($obj['requestBody'], $ctx->push('requestBody'));
        }

        $criteria = [];
        if (($c = $this->optionalList($obj, 'successCriteria', $ctx)) !== null) {
            foreach ($c as $i => $item) {
                $criteria[] = $this->values->parseSuccessCriterion($item, $ctx->push('successCriteria')->push($i));
            }
        }

        $outputs = [];
        if (array_key_exists('outputs', $obj) && $obj['outputs'] !== null) {
            $outputs = $this->values->parseOutputsMap($obj['outputs'], $ctx->push('outputs'));
        }

        return new StepIo(
            parameters: $parameters,
            requestBody: $requestBody,
            successCriteria: $criteria,
            outputs: $outputs,
        );
    }
}
