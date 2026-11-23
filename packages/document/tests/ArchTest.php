<?php

declare(strict_types=1);
use Alama\Arazzo\Contracts\Data\KnownOperations;
use Alama\Arazzo\Document\Document;
use Alama\Arazzo\Document\Parser\Decoders;

arch('document does not depend on illuminate framework')
    ->expect('Alama\Arazzo\Document\Parser')
    ->not->toUse('Illuminate');

arch('document does not leak runner internals')
    ->expect('Alama\Arazzo\Document\Parser')
    ->not->toUse('Alama\Arazzo\Runner\Execution')
    ->not->toUse('Alama\Arazzo\Cli\Console')
    ->not->toUse('Alama\Arazzo\Events')
    ->not->toUse('Alama\Arazzo\Runner\Protocol')
    ->expect('Alama\Arazzo\Document\Validator')
    ->not->toUse('Alama\Arazzo\Runner\Execution')
    ->not->toUse('Alama\Arazzo\Cli\Console')
    ->not->toUse('Alama\Arazzo\Events');

arch('document does not depend on evaluation package')
    ->expect('Alama\Arazzo\Document')
    ->not->toUse('Alama\Arazzo\Evaluation');

arch('document consumes expression engine interface')
    ->expect('Alama\Arazzo\Document\Validator\Rules')
    ->toUse('Alama\Arazzo\Expression\Interfaces\ExpressionEngineInterface')
    ->not->toUse('Alama\Arazzo\Expression\Lexer')
    ->not->toUse('Alama\Arazzo\Expression\Parser');

arch('document facade seams are entry-point only')
    ->expect('Alama\Arazzo\Document\Parser')
    ->not->toUse('Alama\Arazzo\Sources\Document')
    ->not->toUse('Alama\Arazzo\Runner\RunnerFacade')
    ->not->toUse('Alama\Arazzo\Expression\Expression');

arch('document package never depends on sources')
    ->expect('Alama\Arazzo\Document')
    ->not->toUse('Alama\Arazzo\Sources');

arch('document model is vendor-free')
    ->expect('Alama\Arazzo\Document')
    ->not->toUse('cebe')
    ->not->toUse('GuzzleHttp')
    ->not->toUse('Psr\Http')
    ->not->toUse('Psr\SimpleCache')
    ->not->toUse('Softcreatr');

arch('Document is final and readonly')
    ->expect(Document::class)
    ->toBeFinal()
    ->toBeReadonly();

arch('KnownOperations is final and readonly')
    ->expect(KnownOperations::class)
    ->toBeFinal()
    ->toBeReadonly();

test('Decoders class is final and readonly', function (): void {
    $ref = new ReflectionClass(Decoders::class);

    expect($ref->isFinal())->toBeTrue()
        ->and($ref->isReadOnly())->toBeTrue();
});
