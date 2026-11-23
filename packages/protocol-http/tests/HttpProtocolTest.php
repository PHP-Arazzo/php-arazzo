<?php

declare(strict_types=1);

use Alama\Arazzo\Contracts\Interfaces\OperationExecutorPluginInterface;
use Alama\Arazzo\Contracts\Interfaces\OutputExtractorInterface;
use Alama\Arazzo\Document\OperationResolverInterface;
use Alama\Arazzo\Protocol\Http\HttpProtocol;

it('wires executor, resolver, and outputExtractor through HttpProtocol::create()', function (): void {
    $http = HttpProtocol::create();

    expect($http->executor())->toBeInstanceOf(OperationExecutorPluginInterface::class)
        ->and($http->executor(strictValidation: false))->toBeInstanceOf(OperationExecutorPluginInterface::class)
        ->and($http->resolver())->toBeInstanceOf(OperationResolverInterface::class)
        ->and($http->outputExtractor())->toBeInstanceOf(OutputExtractorInterface::class);
});
