<?php

declare(strict_types=1);

namespace Alama\Arazzo\Cli\Console\Command;

use Alama\Arazzo\Cli\Console\RunnerFactory;
use Alama\Arazzo\Contracts\Exceptions\SchemaValidationException;
use Alama\Arazzo\Document\DocumentInterface;
use Alama\Arazzo\Document\Parser\Exceptions\LoaderException;
use Alama\Arazzo\Document\Parser\Exceptions\ParserException;
use Alama\Arazzo\Runner\Data\ExecutionResult;
use Alama\Arazzo\Runner\RunnerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'run', description: 'Execute a workflow from an Arazzo document')]
final class RunCommand extends Command
{
    public function __construct(
        private readonly ?RunnerInterface $runner = null,
        private readonly ?DocumentInterface $document = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('file', InputArgument::REQUIRED, 'Path to an Arazzo YAML/JSON document')
            ->addOption('workflow', 'w', InputOption::VALUE_REQUIRED, 'workflowId to run (defaults to the first workflow)')
            ->addOption('input', 'i', InputOption::VALUE_REQUIRED, 'Workflow inputs as inline JSON or @file.json');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var string $file */
        $file = $input->getArgument('file');

        $documentFacade = $this->document ?? RunnerFactory::defaultDocument();

        try {
            $document = $documentFacade->load($file);
        } catch (LoaderException|ParserException $e) {
            $output->writeln('<error>PARSE ERROR</error> '.$e->getMessage());

            return Command::FAILURE;
        }

        // Resolve relative sourceDescription URLs against the document's
        // directory, not the CWD (the conformance suite runs from the suite
        // root, while case files live in subdirectories). OpenApiOperationResolver
        // uses getcwd() as the base path for relative source URLs.
        chdir(dirname($file));

        /** @var string|null $workflowId */
        $workflowId = $input->getOption('workflow');
        $workflow = $workflowId !== null
            ? $document->findWorkflow($workflowId)
            : $document->firstWorkflow();

        if ($workflow === null) {
            $output->writeln('<error>'.sprintf("unknown workflow '%s'", (string) $workflowId).'</error>');

            return Command::FAILURE;
        }

        /** @var string|null $rawInput */
        $rawInput = $input->getOption('input');
        $inputs = $this->parseInputs($rawInput, $output);
        if ($inputs === null) {
            return Command::FAILURE;
        }

        // Preflight input-schema validation before any side effect.
        $preflightResult = $documentFacade->validateInputs($document, (string) $workflow->workflowId, $inputs);
        if (!$preflightResult->isValid()) {
            foreach ($preflightResult->errors as $error) {
                $output->writeln('<error>'.$error->message.'</error>');
            }

            return Command::FAILURE;
        }

        // Use the validated, default-populated input map (defaults applied).
        $inputs = $preflightResult->inputs;

        $runner = $this->runner ?? RunnerFactory::create(document: $documentFacade);

        /** @var array<string, mixed> $inputs */
        try {
            $result = $runner->run($document, (string) $workflow->workflowId, $inputs);
        } catch (SchemaValidationException $exception) {
            $this->renderSchemaViolation($output, $exception);

            return Command::FAILURE;
        }

        $this->renderOutput($output, $result);

        return $result->isSucceeded() ? Command::SUCCESS : Command::FAILURE;
    }

    /** @return array<string, mixed>|null */
    private function parseInputs(?string $rawInput, OutputInterface $output): ?array
    {
        if (!is_string($rawInput) || $rawInput === '') {
            return [];
        }

        $json = str_starts_with($rawInput, '@') ? (string) file_get_contents(substr($rawInput, 1)) : $rawInput;
        /** @var array<string, mixed>|null $decoded */
        $decoded = json_decode($json, true);

        if (!is_array($decoded)) {
            $output->writeln('<error>--input must be a JSON object or @file containing one</error>');

            return null;
        }

        return $decoded;
    }

    private function renderSchemaViolation(OutputInterface $output, SchemaValidationException $exception): void
    {
        $output->writeln('<error>'.$exception->getMessage().'</error>');

        foreach ($exception->violations as $violation) {
            $output->writeln(sprintf('  <error>%s</error>: %s', $violation['path'], $violation['message']));
        }
    }

    private function renderOutput(OutputInterface $output, ExecutionResult $result): void
    {
        $output->writeln(sprintf('workflow <info>%s</info>: <comment>%s</comment>', $result->workflowId, $result->status->value));

        foreach ($result->stepResults as $stepResult) {
            $status = $stepResult->success ? '<info>✔</info>' : '<error>✘</error>';
            $output->writeln(sprintf('  %s %s', $status, $stepResult->stepId));
        }

        if ($result->outputs !== []) {
            $output->writeln('outputs:');
            foreach ($result->outputs as $name => $value) {
                $output->writeln(sprintf('  %s = %s', $name, json_encode($value)));
            }
        }
    }
}
