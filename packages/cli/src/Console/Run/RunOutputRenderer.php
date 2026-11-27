<?php

declare(strict_types=1);

namespace Alama\Arazzo\Cli\Console\Run;

use Alama\Arazzo\Contracts\Exceptions\SchemaValidationException;
use Alama\Arazzo\Runner\Data\Enum\StepRole;
use Alama\Arazzo\Runner\Data\WorkflowExecutionResult;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Renders workflow execution verdicts, step roles, sequential fallback notices,
 * and schema violations for `arazzo run`.
 *
 * @internal
 */
final class RunOutputRenderer
{
    public function render(OutputInterface $output, WorkflowExecutionResult $result, bool $isParallel = false): void
    {
        $output->writeln(sprintf('workflow <info>%s</info>: <comment>%s</comment>', $result->workflowId, $result->status->value));

        foreach ($result->stepResults as $stepResult) {
            $status = $stepResult->success ? '<info>✔</info>' : '<error>✘</error>';
            $roleTag = match ($stepResult->role) {
                StepRole::Dependency => ' <comment>(dependency)</comment>',
                StepRole::Target => ' <info>(target)</info>',
                StepRole::Standard => '',
            };
            $output->writeln(sprintf('  %s %s%s', $status, $stepResult->stepId, $roleTag));
        }

        if ($isParallel && $result->hasSequentialFallbacks()) {
            foreach ($result->sequentialFallbacks as $fallback) {
                $output->writeln(sprintf('<comment>Notice: %s; running sequentially</comment>', $fallback));
            }
        }

        if ($result->outputs !== []) {
            $output->writeln('outputs:');
            foreach ($result->outputs as $name => $value) {
                $output->writeln(sprintf('  %s = %s', $name, json_encode($value)));
            }
        }
    }

    public function renderViolation(OutputInterface $output, SchemaValidationException $exception): void
    {
        $output->writeln('<error>'.$exception->getMessage().'</error>');

        foreach ($exception->violations as $violation) {
            $output->writeln(sprintf('  <error>%s</error>: %s', $violation['path'], $violation['message']));
        }
    }
}
