<?php

declare(strict_types=1);

namespace Alama\Arazzo\Tests;

use Alama\Arazzo\Contracts\Discovery\PluginDiscovery;
use Alama\Arazzo\Document\Registries\ValidationRuleRegistry;
use Alama\Arazzo\Evaluation\Registries\CriterionEvaluatorRegistry;
use Alama\Arazzo\Evaluation\Registries\ExpressionEvaluatorRegistry;
use Alama\Arazzo\Expression\Registries\SelectorPluginRegistry;
use Alama\Arazzo\Runner\Registries\OperationExecutorRegistry;
use Alama\Arazzo\Sources\Registries\SourceNormalizerRegistry;
use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::resetRegistries();
    }

    protected function tearDown(): void
    {
        self::resetRegistries();
        parent::tearDown();
    }

    private static function resetRegistries(): void
    {
        if (class_exists(PluginDiscovery::class)) {
            PluginDiscovery::reset();
        }
        if (class_exists(SelectorPluginRegistry::class)) {
            SelectorPluginRegistry::reset();
        }
        if (class_exists(SourceNormalizerRegistry::class)) {
            SourceNormalizerRegistry::reset();
        }
        if (class_exists(ValidationRuleRegistry::class)) {
            ValidationRuleRegistry::reset();
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
    }
}
