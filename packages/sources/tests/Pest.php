<?php

declare(strict_types=1);

use Alama\Arazzo\Sources\Registries\SourceNormalizerRegistry;
use Alama\Arazzo\Tests\TestCase;

require_once __DIR__.'/../../../vendor/autoload.php';

pest()->extend(TestCase::class)->in(__DIR__);

uses()->afterEach(function (): void {
    SourceNormalizerRegistry::reset();
})->in(__DIR__);
