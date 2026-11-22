<?php

declare(strict_types=1);

use Alama\Arazzo\Evaluation\Registries\CriterionEvaluatorRegistry;
use Alama\Arazzo\Evaluation\Registries\ExpressionEvaluatorRegistry;
use Alama\Arazzo\Tests\TestCase;

require_once __DIR__.'/../../../vendor/autoload.php';

uses(TestCase::class)->in(__DIR__);

uses()->afterEach(function (): void {
    ExpressionEvaluatorRegistry::reset();
    CriterionEvaluatorRegistry::reset();
})->in(__DIR__);
