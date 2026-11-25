<?php

declare(strict_types=1);

arch('event DTOs are strictly typed and readonly')
    ->expect([
        'Alama\Arazzo\Runner\Events\CorrelationPendingEvent',
        'Alama\Arazzo\Runner\Events\CorrelationResumedEvent',
        'Alama\Arazzo\Runner\Events\RunCompletedEvent',
        'Alama\Arazzo\Runner\Events\RunFailedEvent',
        'Alama\Arazzo\Runner\Events\RunStartedEvent',
        'Alama\Arazzo\Runner\Events\StepExecutedEvent',
        'Alama\Arazzo\Runner\Events\StepFailedEvent',
        'Alama\Arazzo\Runner\Events\StepRetriedEvent',
        'Alama\Arazzo\Runner\Events\StepStartedEvent',
    ])
    ->toBeFinal()
    ->toBeReadonly()
    ->toUseStrictTypes();

arch('runner events stay framework agnostic and do not depend on runner internals')
    ->expect('Alama\Arazzo\Runner\Events')
    ->not->toUse([
        'Illuminate',
        'Alama\Arazzo\Runner\Sync',
        'Alama\Arazzo\Runner\Async',
        'Alama\Arazzo\Runner\Execution',
    ]);
