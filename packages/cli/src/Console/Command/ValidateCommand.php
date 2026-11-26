<?php

declare(strict_types=1);

namespace Alama\Arazzo\Cli\Console\Command;

use Alama\Arazzo\Cli\Console\Config\ValidateConfigLoader;
use Alama\Arazzo\Contracts\Data\KnownOperations;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Validation\Error;
use Alama\Arazzo\Contracts\Validation\ValidationResult;
use Alama\Arazzo\Contracts\Validation\ValidatorPolicy;
use Alama\Arazzo\Contracts\Validation\Warning;
use Alama\Arazzo\Document\Document;
use Alama\Arazzo\Document\Parser\Exceptions\LoaderException;
use Alama\Arazzo\Document\Parser\Exceptions\ParserException;
use Alama\Arazzo\Sources\Sources;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

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
                'config',
                'c',
                InputOption::VALUE_REQUIRED,
                'Path to validator configuration file (arazzo.validate.php or arazzo.json)',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var string $file */
        $file = $input->getArgument('file');

        try {
            $document = Document::create()->load($file);
        } catch (LoaderException|ParserException $e) {
            $output->writeln('<error>PARSE ERROR</error> '.$e->getMessage());

            return Command::FAILURE;
        }

        $policyOrExit = $this->resolvePolicy($input, $file, $output);
        if (is_int($policyOrExit)) {
            return $policyOrExit;
        }

        $knownOperations = $this->resolveKnownOperations($document, (string) dirname($file));

        $result = Document::create()
            ->withValidatorPolicy($policyOrExit)
            ->validate($document, $knownOperations);

        return $this->renderResult($result, $file, $output);
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
        } catch (\Throwable $e) {
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

    private function resolveKnownOperations(ArazzoDocument $document, string $dir): KnownOperations
    {
        try {
            return Sources::create()->knownOperations($document, $dir);
        } catch (\Throwable) {
            return KnownOperations::none();
        }
    }

    private function renderResult(ValidationResult $result, string $file, OutputInterface $output): int
    {
        if ($result->isValid()) {
            $output->writeln('<info>✔ valid</info> '.$file);
            $this->printWarnings($result->warnings, $output);

            return Command::SUCCESS;
        }

        $output->writeln('<error>✘ invalid</error> '.$file);
        $this->printErrors($result->errors, $output);
        $this->printWarnings($result->warnings, $output);

        return Command::FAILURE;
    }

    /**
     * @param  list<Error>  $errors
     */
    private function printErrors(array $errors, OutputInterface $output): void
    {
        if ($errors === []) {
            return;
        }

        $output->writeln('');
        $output->writeln('<fg=red>Errors:</>');
        foreach ($errors as $error) {
            $output->writeln(sprintf(
                '  <fg=red>[%s]</> %s <comment>%s</comment>',
                $error->code,
                $error->message,
                $error->path,
            ));
        }
    }

    /**
     * @param  list<Warning>  $warnings
     */
    private function printWarnings(array $warnings, OutputInterface $output): void
    {
        if ($warnings === []) {
            return;
        }

        $output->writeln('');
        $output->writeln('<comment>Warnings:</comment>');
        foreach ($warnings as $warning) {
            $output->writeln(sprintf(
                '  <fg=yellow>[%s]</> %s <comment>%s</comment>',
                $warning->code,
                $warning->message,
                $warning->path,
            ));
        }
    }
}
