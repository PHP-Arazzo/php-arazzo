<?php

declare(strict_types=1);

namespace Alama\Arazzo\Cli\Console;

use Alama\Arazzo\Document\Document;
use Alama\Arazzo\Document\DocumentInterface;
use Alama\Arazzo\Evaluation\EvaluationEngine;
use Alama\Arazzo\Expression\ExpressionEngine;
use Alama\Arazzo\Protocol\Http\Execution\DefaultOpenApiExecutor;
use Alama\Arazzo\Protocol\Http\Execution\ResponseSchemaValidator;
use Alama\Arazzo\Protocol\Http\Execution\StepOutputExtractor;
use Alama\Arazzo\Protocol\Http\Resolver\OpenApiOperationResolver;
use Alama\Arazzo\Runner\RunnerFacade;
use Alama\Arazzo\Runner\RunnerFacadeInterface;
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
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
final readonly class RunnerFactory
{
    public static function create(
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $httpFactory = null,
        ?SourcesInterface $sources = null,
        ?DocumentInterface $document = null,
    ): RunnerFacadeInterface {
        $client = $httpClient ?? new Client();
        $factory = $httpFactory ?? new HttpFactory();

        $documentFacade = $document ?? Document::create();
        $expressionEngine = new ExpressionEngine();
        $engine = new EvaluationEngine(expression: $expressionEngine);

        $sourcesResolver = $sources ?? Sources::create()->withHttpClient($client, $factory);
        $resolver = OpenApiOperationResolver::create($sourcesResolver);

        return new RunnerFacade(
            $documentFacade,
            $resolver,
            $engine,
            inspector: null,
            openApiExecutor: new DefaultOpenApiExecutor($client, $factory),
            outputExtractor: new StepOutputExtractor($resolver, $engine, $expressionEngine),
            schemaValidator: new ResponseSchemaValidator($resolver),
        );
    }
}
