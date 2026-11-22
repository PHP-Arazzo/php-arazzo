<?php

declare(strict_types=1);

namespace Alama\Arazzo\Tests;

use Alama\Arazzo\Evaluation\Registries\CriterionEvaluatorRegistry;
use Alama\Arazzo\Evaluation\Registries\ExpressionEvaluatorRegistry;
use Alama\Arazzo\Expression\Registries\SelectorPluginRegistry;
use Alama\Arazzo\Runner\OperationExecutorRegistry;
use Alama\Arazzo\Sources\Resolver\SourceNormalizerRegistry;
use Alama\Arazzo\Sources\SourceGraph;
use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function tearDown(): void
    {
        if (class_exists(SelectorPluginRegistry::class)) {
            SelectorPluginRegistry::reset();
        }
        if (class_exists(SourceNormalizerRegistry::class)) {
            SourceNormalizerRegistry::reset();
        }
        if (class_exists(SourceGraph::class)) {
            SourceGraph::reset();
        }
        if (class_exists(OperationExecutorRegistry::class)) {
            OperationExecutorRegistry::reset();
        }
        if (class_exists(ExpressionEvaluatorRegistry::class)) {
            ExpressionEvaluatorRegistry::reset();
        }
        if (class_exists(CriterionEvaluatorRegistry::class)) {
            CriterionEvaluatorRegistry::reset();
        }

        parent::tearDown();
    }
}
