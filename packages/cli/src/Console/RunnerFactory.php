<?php

declare(strict_types=1);

namespace Alama\Arazzo\Cli\Console;

use Alama\Arazzo\Document\Document;
use Alama\Arazzo\Document\DocumentInterface;
use Alama\Arazzo\Protocol\Http\HttpProtocol;
use Alama\Arazzo\Protocol\Http\Policy\HttpPolicy;
use Alama\Arazzo\Runner\Runner;
use Alama\Arazzo\Runner\RunnerInterface;
use Alama\Arazzo\Sources\Sources;
use Alama\Arazzo\Sources\SourcesInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;

/**
 * Composition root for assembling the default HTTP workflow runner in CLI.
 *
 * @internal stays out of the advertised contract; not part of the public API surface
 */
readonly class RunnerFactory
{
    public function __construct(
        private ?ClientInterface $httpClient = null,
        private ?RequestFactoryInterface $httpFactory = null,
        private ?SourcesInterface $sources = null,
    ) {}

    public function createRunner(
        ?DocumentInterface $document = null,
        ?HttpPolicy $httpPolicy = null,
    ): RunnerInterface {
        return self::create(
            httpClient: $this->httpClient,
            httpFactory: $this->httpFactory,
            sources: $this->sources,
            document: $document,
            httpPolicy: $httpPolicy,
        );
    }

    public static function defaultDocument(): DocumentInterface
    {
        return Document::create();
    }

    public static function create(
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $httpFactory = null,
        ?SourcesInterface $sources = null,
        ?DocumentInterface $document = null,
        ?HttpPolicy $httpPolicy = null,
    ): RunnerInterface {
        $documentFacade = $document ?? Document::create();
        $sourcesResolver = $sources ?? Sources::create();

        $http = HttpProtocol::create($httpClient, $httpFactory, $sourcesResolver);
        if ($httpPolicy !== null) {
            $http = $http->withPolicy($httpPolicy);
        }

        return (new Runner($documentFacade))
            ->withExecutor($http->executor())
            ->withOperationResolver($http->resolver());
    }
}
