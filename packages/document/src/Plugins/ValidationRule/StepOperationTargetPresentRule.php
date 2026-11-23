<?php

declare(strict_types=1);

namespace Alama\Arazzo\Document\Plugins\ValidationRule;

use Alama\Arazzo\Contracts\Attributes\AsValidationRulePlugin;
use Alama\Arazzo\Contracts\Interfaces\ValidationRuleInterface;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Validation\ErrorCollector;
use Alama\Arazzo\Contracts\Validation\SymbolTable;

/**
 * @internal stays out of the advertised contract; not part of the public API surface
 */
#[AsValidationRulePlugin]
final class StepOperationTargetPresentRule implements ValidationRuleInterface
{
    public function check(ArazzoDocument $doc, SymbolTable $symbols, ErrorCollector $errors): void
    {
        foreach ($doc->workflows as $i => $w) {
            foreach ($w->steps as $j => $s) {
                $target = $s->target;
                $kinds = [];

                // An async step selects its operation or channel through
                // operationId/channelPath, so those are selectors *within* the
                // async target rather than a separate HTTP target. action and
                // channelPath together form one target kind (Arazzo 1.1 §5.8.5).
                $isAsync = $target->action !== null || $target->channelPath !== null;

                if ($isAsync) {
                    $kinds[] = 'action/channelPath';
                } else {
                    if ($target->operationId !== null) {
                        $kinds[] = 'operationId';
                    }
                    if ($target->operationPath !== null) {
                        $kinds[] = 'operationPath';
                    }
                }

                foreach ([
                    'workflowId' => $target->workflowId,
                    'operationName' => $target->operationName,
                    'rpcMethod' => $target->rpcMethod,
                    'interaction' => $target->interaction,
                    'graphqlOperation' => $target->graphqlOperation,
                ] as $field => $value) {
                    if ($value !== null) {
                        $kinds[] = $field;
                    }
                }

                if (count($kinds) !== 1) {
                    $errors->error(
                        $this->code(),
                        "Step '{$s->stepId}' must set exactly one target: operationId/operationPath, action with operationId or channelPath, workflowId, operationName, rpcMethod, interaction, or graphqlOperation (got ".count($kinds).').',
                        "/workflows/{$i}/steps/{$j}",
                    );
                }
            }
        }
    }

    public function code(): string
    {
        return 'step.operation_target_present';
    }
}
