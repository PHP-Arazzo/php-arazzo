<?php

declare(strict_types=1);

namespace Alama\Arazzo\Evaluation\Tests\Registries;

use Alama\Arazzo\Contracts\Attributes\AsCriterionPlugin;
use Alama\Arazzo\Contracts\Attributes\AsExpressionPlugin;
use Alama\Arazzo\Contracts\Interfaces\CriterionEvaluatorPluginInterface;
use Alama\Arazzo\Contracts\Interfaces\ExpressionEvaluatorPluginInterface;
use Alama\Arazzo\Contracts\Spec\Enum\CriterionType;
use Alama\Arazzo\Contracts\Spec\Expression;
use Alama\Arazzo\Contracts\Spec\Interfaces\WorkflowContextInterface;
use Alama\Arazzo\Contracts\Spec\Step;
use Alama\Arazzo\Contracts\Spec\SuccessCriterion;
use Alama\Arazzo\Evaluation\Plugins\JsonPathCriterionPlugin;
use Alama\Arazzo\Evaluation\Plugins\JsonPathExpressionPlugin;
use Alama\Arazzo\Evaluation\Registries\CriterionEvaluatorRegistry;
use Alama\Arazzo\Evaluation\Registries\ExpressionEvaluatorRegistry;
use Alama\Arazzo\Expression\ExpressionEngine;

#[AsExpressionPlugin(priority: 50)]
class FakeAttributedExpressionPlugin implements ExpressionEvaluatorPluginInterface
{
    public function name(): string
    {
        return 'fake-expr';
    }

    public function priority(): int
    {
        return 0;
    }

    public function supports(Expression $expression): bool
    {
        return str_starts_with($expression->raw, '$custom.');
    }

    public function evaluate(Expression $expression, mixed $context): mixed
    {
        return 'custom-evaluated';
    }
}

#[AsCriterionPlugin('custom', priority: 50)]
class FakeAttributedCriterionPlugin implements CriterionEvaluatorPluginInterface
{
    public function name(): string
    {
        return 'fake-crit';
    }

    public function priority(): int
    {
        return 0;
    }

    public function supports(CriterionType|SuccessCriterion $criterion): bool
    {
        if ($criterion instanceof SuccessCriterion) {
            return $criterion->type === CriterionType::Simple && $criterion->condition === 'custom';
        }

        return false;
    }

    public function evaluate(SuccessCriterion $criterion, mixed $context, Step $step, WorkflowContextInterface $workflowContext): bool
    {
        return true;
    }
}

beforeEach(function (): void {
    ExpressionEvaluatorRegistry::reset();
    CriterionEvaluatorRegistry::reset();
});

it('operates ExpressionEvaluatorRegistry as a singleton with create() and reset()', function (): void {
    $engine = new ExpressionEngine();
    $a = ExpressionEvaluatorRegistry::create($engine);
    $b = ExpressionEvaluatorRegistry::create();

    expect($a)->toBe($b);

    ExpressionEvaluatorRegistry::reset();
    $c = ExpressionEvaluatorRegistry::create($engine);

    expect($c)->not->toBe($a);
});

it('auto-registers default JsonPathExpressionPlugin in ExpressionEvaluatorRegistry', function (): void {
    $registry = ExpressionEvaluatorRegistry::create(new ExpressionEngine());
    $plugins = $registry->all();

    expect($plugins)->toHaveCount(1)
        ->and($plugins[0])->toBeInstanceOf(JsonPathExpressionPlugin::class);
});

it('discovers priority from #[AsExpressionPlugin] attribute', function (): void {
    $registry = ExpressionEvaluatorRegistry::create(new ExpressionEngine());
    $custom = new FakeAttributedExpressionPlugin();
    $registry->register($custom);

    $plugins = $registry->all();
    expect($plugins[0])->toBe($custom); // priority 50 > default 0
});

it('operates CriterionEvaluatorRegistry as a singleton with create() and reset()', function (): void {
    $engine = new ExpressionEngine();
    $a = CriterionEvaluatorRegistry::create($engine);
    $b = CriterionEvaluatorRegistry::create();

    expect($a)->toBe($b);

    CriterionEvaluatorRegistry::reset();
    $c = CriterionEvaluatorRegistry::create($engine);

    expect($c)->not->toBe($a);
});

it('auto-registers default JsonPathCriterionPlugin in CriterionEvaluatorRegistry', function (): void {
    $registry = CriterionEvaluatorRegistry::create(new ExpressionEngine());
    $plugins = $registry->all();

    expect($plugins)->toHaveCount(1)
        ->and($plugins[0])->toBeInstanceOf(JsonPathCriterionPlugin::class);
});

it('discovers priority from #[AsCriterionPlugin] attribute', function (): void {
    $registry = CriterionEvaluatorRegistry::create(new ExpressionEngine());
    $custom = new FakeAttributedCriterionPlugin();
    $registry->register($custom);

    $plugins = $registry->all();
    expect($plugins[0])->toBe($custom); // priority 50 > default 0
});
