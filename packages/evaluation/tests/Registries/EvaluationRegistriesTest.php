<?php

declare(strict_types=1);

namespace Alama\Arazzo\Evaluation\Tests\Registries;

use Alama\Arazzo\Contracts\Attributes\AsCriterionPlugin;
use Alama\Arazzo\Contracts\Attributes\AsExpressionPlugin;
use Alama\Arazzo\Contracts\Interfaces\CriterionEvaluatorPluginInterface;
use Alama\Arazzo\Contracts\Interfaces\ExpressionEvaluatorPluginInterface;
use Alama\Arazzo\Contracts\Spec\Enum\CriterionType;
use Alama\Arazzo\Contracts\Spec\Enum\ExpressionType;
use Alama\Arazzo\Contracts\Spec\Expression;
use Alama\Arazzo\Contracts\Spec\Interfaces\WorkflowContextInterface;
use Alama\Arazzo\Contracts\Spec\Step;
use Alama\Arazzo\Contracts\Spec\SuccessCriterion;
use Alama\Arazzo\Evaluation\Plugins\Criterion\JsonPathCriterionPlugin;
use Alama\Arazzo\Evaluation\Plugins\Expression\JsonPathExpressionPlugin;
use Alama\Arazzo\Evaluation\Registries\CriterionEvaluatorRegistry;
use Alama\Arazzo\Evaluation\Registries\ExpressionEvaluatorRegistry;
use Alama\Arazzo\Expression\ExpressionEngine;

#[AsExpressionPlugin(ExpressionType::JsonPath)]
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

#[AsCriterionPlugin(CriterionType::JsonPath)]
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
            return $criterion->type === CriterionType::JsonPath;
        }

        return $criterion === CriterionType::JsonPath;
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

afterEach(function (): void {
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

it('auto-discovers default JsonPathExpressionPlugin in ExpressionEvaluatorRegistry', function (): void {
    $registry = ExpressionEvaluatorRegistry::create(new ExpressionEngine());
    $plugins = $registry->all();

    expect($plugins)->toHaveCount(1)
        ->and($plugins[0])->toBeInstanceOf(JsonPathExpressionPlugin::class);
});

it('replaces plugin for the ExpressionType declared via #[AsExpressionPlugin] (last-registered wins)', function (): void {
    $registry = ExpressionEvaluatorRegistry::create(new ExpressionEngine());
    $custom = new FakeAttributedExpressionPlugin();
    $registry->register($custom);

    $plugins = $registry->all();
    expect($plugins)->toHaveCount(1)
        ->and($plugins[0])->toBe($custom);
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

it('auto-discovers default JsonPathCriterionPlugin in CriterionEvaluatorRegistry', function (): void {
    $registry = CriterionEvaluatorRegistry::create(new ExpressionEngine());
    $plugins = $registry->all();

    expect($plugins)->toHaveCount(1)
        ->and($plugins[0])->toBeInstanceOf(JsonPathCriterionPlugin::class);
});

it('replaces plugin for the CriterionType declared via #[AsCriterionPlugin] (last-registered wins)', function (): void {
    $registry = CriterionEvaluatorRegistry::create(new ExpressionEngine());
    $custom = new FakeAttributedCriterionPlugin();
    $registry->register($custom);

    $plugins = $registry->all();
    expect($plugins)->toHaveCount(1)
        ->and($plugins[0])->toBe($custom);
});
