<?php

declare(strict_types=1);

namespace Alama\Arazzo\Cli\Console\Command;

use Alama\Arazzo\Cli\Console\Config\ValidateConfigLoader;
use Alama\Arazzo\Contracts\Validation\Error;
use Alama\Arazzo\Contracts\Validation\ValidatorPolicy;
use Alama\Arazzo\Contracts\Validation\Warning;
use Alama\Arazzo\Document\Document;
use Alama\Arazzo\Sources\Policy\FetchPolicy;
use Alama\Arazzo\Sources\Sources;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

#[AsCommand(name: 'validate', description: 'Parse and validate an Arazzo document')]
final class ValidateCommand extends Command
{
    public function __construct(
        private readonly ValidateConfigLoader $configLoader = new ValidateConfigLoader(),
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('file', InputArgument::REQUIRED, 'Path to an Arazzo YAML/JSON document')
            ->addOption(
                'severity',
                's',
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Override rule severity in format <code>:<warn|warning|error|err>',
            )
            ->addOption(
                'disable-rule',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Disable one or more validator rules by code',
            )
            ->addOption(
                'enable-rule',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Enable one or more validator rules by code (overrides config)',
            )
            ->addOption(
                'strict',
                null,
                InputOption::VALUE_NONE,
                'Enable strict validation mode',
            )
            ->addOption(
                'no-strict',
                null,
                InputOption::VALUE_NONE,
                'Disable strict validation mode',
            )
            ->addOption(
                'conformance',
                null,
                InputOption::VALUE_NONE,
                'Enforce strict Arazzo specification conformance (reject beyond-spec extensions)',
            )
            ->addOption(
                'config',
                'c',
                InputOption::VALUE_REQUIRED,
                'Path to validator configuration file (arazzo.validate.php or arazzo.json)',
            )
            ->addOption(
                'resolve-sources',
                null,
                InputOption::VALUE_NONE,
                'Fetch and validate reachability, parseability and declared type of all source descriptions',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var string $file */
        $file = $input->getArgument('file');

        try {
            $document = Document::create()->load($file);
        } catch (Throwable $e) {
            $output->writeln('<error>PARSE ERROR</error> '.$e->getMessage());

            return Command::FAILURE;
        }

        $policyOrExit = $this->resolvePolicy($input, $file, $output);
        if (is_int($policyOrExit)) {
            return $policyOrExit;
        }

        $resolveSources = (bool) $input->getOption('resolve-sources');
        $knownOperations = null;
        $sourceResolutions = null;

        if ($resolveSources) {
            try {
                $inspection = Sources::create()->inspectSources($document, (string) dirname($file));
                $knownOperations = $inspection->knownOperations;
                $sourceResolutions = $inspection->resolutions;
            } catch (Throwable) {
                // Degrade gracefully
            }
        } else {
            try {
                $offlinePolicy = FetchPolicy::default()->withAllowedSchemes(['file']);
                $knownOperations = Sources::create()->withPolicy($offlinePolicy)->knownOperations($document, (string) dirname($file));
            } catch (Throwable) {
                // Degrade gracefully
            }
        }

        $result = Document::create()
            ->withValidatorPolicy($policyOrExit)
            ->validate($document, $knownOperations, $sourceResolutions);

        if ($result->isValid()) {
            $output->writeln('<info>✔ valid</info> '.$file);
            $this->printDiagnostics($result->warnings, '<comment>Warnings:</comment>', 'yellow', $output);

            return Command::SUCCESS;
        }

        $output->writeln('<error>✘ invalid</error> '.$file);
        $this->printDiagnostics($result->errors, '<fg=red>Errors:</>', 'red', $output);
        $this->printDiagnostics($result->warnings, '<comment>Warnings:</comment>', 'yellow', $output);

        return Command::FAILURE;
    }

    /**
     * @return ValidatorPolicy|int Returns ValidatorPolicy on success or command exit code on error.
     */
    private function resolvePolicy(InputInterface $input, string $file, OutputInterface $output): ValidatorPolicy|int
    {
        /** @var string|null $configPath */
        $configPath = $input->getOption('config');
        try {
            $policy = $this->configLoader->load(
                explicitPath: is_string($configPath) ? $configPath : null,
                workingDirectory: dirname($file),
            );
        } catch (Throwable $e) {
            $output->writeln('<error>CONFIG ERROR</error> '.$e->getMessage());

            return Command::FAILURE;
        }

        /** @var list<string> $disableRules */
        $disableRules = (array) $input->getOption('disable-rule');
        if ($disableRules !== []) {
            $policy = $policy->withDisabled(...$disableRules);
        }

        /** @var list<string> $enableRules */
        $enableRules = (array) $input->getOption('enable-rule');
        if ($enableRules !== []) {
            $policy = $policy->withEnabled(...$enableRules);
        }

        if ((bool) $input->getOption('no-strict')) {
            $policy = $policy->withStrict(false);
        } elseif ((bool) $input->getOption('strict')) {
            $policy = $policy->withStrict(true);
        }

        if ((bool) $input->getOption('conformance')) {
            $policy = $policy->withConformance(true);
        }

        return $this->applySeverityFlags($input, $policy, $output);
    }

    private function applySeverityFlags(InputInterface $input, ValidatorPolicy $policy, OutputInterface $output): ValidatorPolicy|int
    {
        /** @var list<string> $severities */
        $severities = (array) $input->getOption('severity');
        foreach ($severities as $spec) {
            $parts = explode(':', $spec, 2);
            if (count($parts) !== 2 || trim($parts[0]) === '' || trim($parts[1]) === '') {
                $output->writeln(sprintf("<error>Invalid severity option '%s'. Expected format <code>:<warn|warning|error|err></error>", $spec));

                return Command::INVALID;
            }

            $severity = $this->configLoader->parseSeverityLevel($parts[1]);
            if ($severity === null) {
                $output->writeln(sprintf("<error>Invalid severity option '%s'. Level must be 'warn', 'warning', 'err', or 'error'.</error>", $spec));

                return Command::INVALID;
            }

            $policy = $policy->withSeverity(trim($parts[0]), $severity);
        }

        return $policy;
    }

    /**
     * @param  list<Error|Warning>  $diagnostics
     */
    private function printDiagnostics(array $diagnostics, string $heading, string $color, OutputInterface $output): void
    {
        if ($diagnostics === []) {
            return;
        }

        $output->writeln('');
        $output->writeln($heading);
        foreach ($diagnostics as $diagnostic) {
            $output->writeln(sprintf(
                '  <fg=%s>[%s]</> %s <comment>%s</comment>',
                $color,
                $diagnostic->code,
                $diagnostic->message,
                $diagnostic->path,
            ));
        }
    }
}
