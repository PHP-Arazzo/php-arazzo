<?php

declare(strict_types=1);

arch('engine does not depend on the runner (F3 gate: InjectionResult inversion)')
    ->expect('Alama\Arazzo\Engine')
    ->not->toUse('Alama\Arazzo\Runner');

arch('engine RetryPolicy follows the *Policy final readonly standard (ADR-010)')
    ->expect('Alama\Arazzo\Runtime\Policy\RetryPolicy')
    ->toBeFinal()
    ->toBeReadonly();

arch('engine does not depend on psr/log')
    ->expect(['Alama\Arazzo\Engine', 'Alama\Arazzo\Runtime'])
    ->not->toUse('Psr\Log');
