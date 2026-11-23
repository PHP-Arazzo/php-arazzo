<?php

declare(strict_types=1);

arch('protocol-rpc does not depend on the runner, the other protocols, or a framework')
    ->expect('Alama\Arazzo\Protocol\Rpc')
    ->not->toUse(['Alama\Arazzo\Runner', 'Alama\Arazzo\Protocol\Http', 'Alama\Arazzo\Protocol\Soap', 'Illuminate']);

arch('protocol-rpc stays vendor-agnostic on observability (ADR-010 Tier 2)')
    ->expect('Alama\Arazzo\Protocol\Rpc')
    ->not->toUse('OpenTelemetry')
    ->not->toUse('Psr\EventDispatcher');
