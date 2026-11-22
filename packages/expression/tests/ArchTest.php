<?php

declare(strict_types=1);

arch('expression does not depend on illuminate framework')
    ->expect('Alama\Arazzo\Expression')
    ->not->toUse('Illuminate');

arch('expression does not leak document/runner internals')
    ->expect('Alama\Arazzo\Expression')
    ->not->toUse('Alama\Arazzo\Document\Parser')
    ->not->toUse('Alama\Arazzo\Runner\Execution')
    ->not->toUse('Alama\Arazzo\Cli\Console')
    ->not->toUse('Alama\Arazzo\Document\Validator');

arch('expression does not use evaluation classes')
    ->expect('Alama\Arazzo\Expression')
    ->not->toUse('Alama\Arazzo\Evaluation');

arch('expression facade seams are entry-point only')
    ->expect('Alama\Arazzo\Expression')
    ->not->toUse('Alama\Arazzo\Sources\Document')
    ->not->toUse('Alama\Arazzo\Runner\RunnerFacade');

arch('ExpressionDependencyProvider stays inside packages/expression')
    ->expect('Alama\Arazzo\Document')
    ->not->toUse('Alama\Arazzo\Expression\ExpressionDependencyProvider')
    ->and('Alama\Arazzo\Evaluation')
    ->not->toUse('Alama\Arazzo\Expression\ExpressionDependencyProvider')
    ->and('Alama\Arazzo\Runner')
    ->not->toUse('Alama\Arazzo\Expression\ExpressionDependencyProvider')
    ->and('Alama\Arazzo\Sources')
    ->not->toUse('Alama\Arazzo\Expression\ExpressionDependencyProvider')
    ->and('Alama\Arazzo\Cli')
    ->not->toUse('Alama\Arazzo\Expression\ExpressionDependencyProvider');

arch('expression public API contracts')
    ->expect('Alama\Arazzo\Expression\ExpressionEngine')
    ->toBeFinal()
    ->toBeReadonly()
    ->and('Alama\Arazzo\Expression\Registries\SelectorPluginRegistry')
    ->toBeFinal();

arch('expression has zero dependencies on document/runner/sources/evaluation')
    ->expect('Alama\Arazzo\Expression')
    ->not->toUse('Alama\Arazzo\Document')
    ->not->toUse('Alama\Arazzo\Runner')
    ->not->toUse('Alama\Arazzo\Sources')
    ->not->toUse('Alama\Arazzo\Evaluation');
