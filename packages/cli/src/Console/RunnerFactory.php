<?php

declare(strict_types=1);

namespace Alama\Arazzo\Cli\Console;

use Alama\Arazzo\Document\Document;
use Alama\Arazzo\Document\DocumentInterface;
use Alama\Arazzo\Evaluation\EvaluationEngine;
use Alama\Arazzo\Expression\ExpressionEngine;
use Alama\Arazzo\Protocol\Http\HttpProtocol;
use Alama\Arazzo\Runner\Runner;
use Alama\Arazzo\Runner\RunnerInterface;
use Alama\Arazzo\Sources\Sources;
use Alama\Arazzo\Sources\SourcesInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;

/**
 * Composition root for assembling the default HTTP workflow runner in CLI.
 *
 * @internal stays out of the advertised contract; not part of the public API surface
 */
final readonly class RunnerFactory
{
    public static function defaultDocument(): DocumentInterface
    {
        return Document::create();
    }

    public static function create(
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $httpFactory = null,
        ?SourcesInterface $sources = null,
        ?DocumentInterface $document = null,
    ): RunnerInterface {
        $client = $httpClient ?? new Client();
        $factory = $httpFactory ?? new HttpFactory();

        $documentFacade = $document ?? Document::create();
        $expressionEngine = ExpressionEngine::create();
        $engine = new EvaluationEngine(expression: $expressionEngine);

        $sourcesResolver = $sources ?? Sources::create()->withHttpClient($client, $factory);
        $http = HttpProtocol::create($client, $factory, $sourcesResolver, $engine, $expressionEngine);

        return (new Runner($documentFacade, $engine))
            ->withExecutor($http->executor())
            ->withOperationResolver($http->resolver());
    }
}
