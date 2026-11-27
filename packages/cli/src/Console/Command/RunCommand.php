<?php

declare(strict_types=1);

namespace Alama\Arazzo\Cli\Console\Command;

use Alama\Arazzo\Cli\Console\Run\RunExecutor;
use Alama\Arazzo\Cli\Console\RunnerFactory;
use Alama\Arazzo\Document\DocumentInterface;
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
        private readonly RunnerFactory $runnerFactory = new RunnerFactory(),
        private readonly RunExecutor $executor = new RunExecutor(),
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('file', InputArgument::REQUIRED, 'Path to an Arazzo YAML/JSON document')
            ->addOption('workflow', 'w', InputOption::VALUE_REQUIRED, 'workflowId to run (defaults to the first workflow)')
            ->addOption('step', 's', InputOption::VALUE_REQUIRED, 'Target stepId to execute (with transitive dependencies)')
            ->addOption('no-deps', null, InputOption::VALUE_NONE, 'Execute only the target step, skipping upstream dependencies')
            ->addOption('parallel', null, InputOption::VALUE_NONE, 'Execute independent steps in parallel')
            ->addOption('input', 'i', InputOption::VALUE_REQUIRED, 'Workflow inputs as inline JSON or @file.json')
            ->addOption('rate-limit', null, InputOption::VALUE_REQUIRED, 'Outbound requests per second limit (e.g. 10)')
            ->addOption('rate-burst', null, InputOption::VALUE_REQUIRED, 'Outbound rate limit burst capacity (default: 1)')
            ->addOption('max-concurrency', null, InputOption::VALUE_REQUIRED, 'Max concurrent in-flight requests (default: 4)')
            ->addOption('rate-limit-scope', null, InputOption::VALUE_REQUIRED, 'Rate limit scope: host or run (default: host)')
            ->addOption('rate-limit-fail-fast', null, InputOption::VALUE_NONE, 'Fail fast with transport.rate_limited instead of waiting when rate limited');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return $this->executor->execute(
            $input,
            $output,
            $this->runner,
            $this->document,
            $this->runnerFactory,
        );
    }
}
