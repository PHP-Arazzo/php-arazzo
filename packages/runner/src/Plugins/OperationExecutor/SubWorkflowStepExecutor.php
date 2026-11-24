<?php

declare(strict_types=1);

namespace Alama\Arazzo\Runner\Plugins\OperationExecutor;

use Alama\Arazzo\Contracts\Attributes\AsOperationExecutorPlugin;
use Alama\Arazzo\Contracts\Interfaces\OperationExecutorPluginInterface;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Enum\OperationProtocol;
use Alama\Arazzo\Contracts\Spec\Expression;
use Alama\Arazzo\Contracts\Spec\Step;
use Alama\Arazzo\Contracts\Spec\StepExecutionOutcome;
use Alama\Arazzo\Contracts\State\WorkflowContext;
use Alama\Arazzo\Engine\Exceptions\ExecutionException;
use Alama\Arazzo\Engine\Execution\ReusableParameterResolver;
use Alama\Arazzo\Evaluation\EvaluationEngineInterface;
use Alama\Arazzo\Expression\Data\EvaluationContext;
use Alama\Arazzo\Runner\Sync\WorkflowExecutor;

/**
 * Executes steps that target a nested workflow via workflowId. The child
 * workflow runs in its own child context; its outputs are surfaced as the
 * step's outputs so parent expressions like {$steps.s.outputs.x} resolve.
 *
 * @internal stays out of the advertised contract; not part of the public API surface
 */
#[AsOperationExecutorPlugin(OperationProtocol::SubWorkflow)]
final class SubWorkflowStepExecutor implements OperationExecutorPluginInterface
{
    public function __construct(
        private WorkflowExecutor $executor,
        private EvaluationEngineInterface $evaluationEngine,
    ) {}

    public function name(): string
    {
        return 'subworkflow';
    }

    public function priority(): int
    {
        return 0;
    }

    public function supports(Step $step, ArazzoDocument $document): bool
    {
        return $step->isSubWorkflow();
    }

    public function execute(Step $step, WorkflowContext $context, ArazzoDocument $document, string $executionId): StepExecutionOutcome
    {
        $target = $document->findWorkflow($step->target->workflowId);

        if ($target === null) {
            throw ExecutionException::subWorkflowNotFound((string) $step->target->workflowId);
        }

        $evaluationContext = new EvaluationContext($context, $step->stepId, $document);

        $bound = [];
        $parameters = (new ReusableParameterResolver())->resolve($step->io->parameters, $document);

        foreach ($parameters as $parameter) {
            $bound[$parameter->name] = $parameter->value instanceof Expression
                ? $this->evaluationEngine->evaluate($parameter->value, $evaluationContext)
                : $parameter->value;
        }

        $child = WorkflowContext::forChildInvocation($context, $target, $bound);
        $result = $this->executor->execute($target, $document, $bound, $child);

        // A completed sub-workflow has no HTTP semantics; 200 marks success.
        return StepExecutionOutcome::resolved(200, $result->outputs, [], $bound, [
            'method' => 'WORKFLOW',
            'url' => '#workflows/'.$target->workflowId,
        ]);
    }
}
