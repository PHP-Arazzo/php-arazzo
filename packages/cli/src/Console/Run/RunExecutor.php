<?php

declare(strict_types=1);

namespace Alama\Arazzo\Cli\Console\Run;

use Alama\Arazzo\Cli\Console\RunnerFactory;
use Alama\Arazzo\Contracts\Exceptions\SchemaValidationException;
use Alama\Arazzo\Document\DocumentInterface;
use Alama\Arazzo\Protocol\Http\Policy\HttpPolicy;
use Alama\Arazzo\Runner\Policy\ExecutionPolicy;
use Alama\Arazzo\Runner\RunnerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Coordinates preflight validation, policy assembly, runner dispatch, and
 * output rendering for `arazzo run`.
 *
 * @internal
 */
final class RunExecutor
{
    public function __construct(
        private readonly RunPreflight $preflight = new RunPreflight(),
        private readonly RunOutputRenderer $renderer = new RunOutputRenderer(),
    ) {}

    public function execute(
        InputInterface $input,
        OutputInterface $output,
        ?RunnerInterface $runner = null,
        ?DocumentInterface $document = null,
        RunnerFactory $runnerFactory = new RunnerFactory(),
    ): int {
        /** @var string|null $stepId */
        $stepId = $input->getOption('step');
        $noDeps = (bool) $input->getOption('no-deps');

        if ($noDeps && $stepId === null) {
            $output->writeln('<error>The "--no-deps" option requires "--step" to be specified.</error>');

            return Command::FAILURE;
        }

        $documentFacade = $document ?? RunnerFactory::defaultDocument();
        /** @var string $file */
        $file = $input->getArgument('file');
        $arazzoDoc = $this->preflight->load($documentFacade, $file, $output);
        if ($arazzoDoc === null) {
            return Command::FAILURE;
        }

        /** @var string|null $workflowId */
        $workflowId = $input->getOption('workflow');
        $workflow = $this->preflight->resolveWorkflow($arazzoDoc, $stepId, $workflowId, $output);
        if ($workflow === null) {
            return Command::FAILURE;
        }

        $inputs = $this->preflight->resolveInputs($documentFacade, $arazzoDoc, $workflow, $input, $output);
        if ($inputs === null) {
            return Command::FAILURE;
        }

        $httpPolicy = $this->buildHttpPolicy($input);
        $activeRunner = $this->resolveRunner($runner, $runnerFactory, $documentFacade, $httpPolicy);

        try {
            $result = $stepId !== null
                ? $activeRunner->runStep($arazzoDoc, $stepId, $inputs, (string) $workflow->workflowId, ExecutionPolicy::default()->withNoDeps($noDeps))
                : $activeRunner->run($arazzoDoc, (string) $workflow->workflowId, $inputs);
        } catch (SchemaValidationException $exception) {
            $this->renderer->renderViolation($output, $exception);

            return Command::FAILURE;
        }

        $this->renderer->render($output, $result, (bool) $input->getOption('parallel'));

        return $result->isSucceeded() ? Command::SUCCESS : Command::FAILURE;
    }

    private function resolveRunner(
        ?RunnerInterface $runner,
        RunnerFactory $runnerFactory,
        DocumentInterface $document,
        ?HttpPolicy $httpPolicy,
    ): RunnerInterface {
        return $runner ?? $runnerFactory->createRunner($document, $httpPolicy);
    }

    private function buildHttpPolicy(InputInterface $input): ?HttpPolicy
    {
        /** @var string|null $rateLimit */
        $rateLimit = $input->getOption('rate-limit');
        /** @var string|null $rateBurst */
        $rateBurst = $input->getOption('rate-burst');
        /** @var string|null $maxConcurrency */
        $maxConcurrency = $input->getOption('max-concurrency');
        /** @var string|null $scope */
        $scope = $input->getOption('rate-limit-scope');
        $failFast = (bool) $input->getOption('rate-limit-fail-fast');

        if ($this->isDefaultRateLimit($rateLimit, $rateBurst, $maxConcurrency, $scope, $failFast)) {
            return null;
        }

        return $this->assembleHttpPolicy($rateLimit, $rateBurst, $maxConcurrency, $scope, $failFast);
    }

    private function isDefaultRateLimit(
        ?string $rateLimit,
        ?string $rateBurst,
        ?string $maxConcurrency,
        ?string $scope,
        bool $failFast,
    ): bool {
        if ($rateLimit !== null || $failFast) {
            return false;
        }

        $burstDefault = $rateBurst === null || (int) $rateBurst === 1;
        $concurrencyDefault = $maxConcurrency === null || (int) $maxConcurrency === 4;
        $scopeDefault = $scope === null || $scope === 'host';

        return $burstDefault && $concurrencyDefault && $scopeDefault;
    }

    private function assembleHttpPolicy(
        ?string $rateLimit,
        ?string $rateBurst,
        ?string $maxConcurrency,
        ?string $scope,
        bool $failFast,
    ): HttpPolicy {
        $policy = HttpPolicy::default();

        if ($maxConcurrency !== null) {
            $policy = $policy->withMaxConcurrent((int) $maxConcurrency);
        }

        if ($scope !== null) {
            $policy = $policy->withScope($scope);
        }

        if ($failFast) {
            $policy = $policy->withFailFast(true);
        }

        if ($rateLimit !== null) {
            $burst = $rateBurst !== null ? (int) $rateBurst : 1;
            $policy = $policy->withRateLimit((float) $rateLimit, $burst);
        }

        return $policy;
    }
}
