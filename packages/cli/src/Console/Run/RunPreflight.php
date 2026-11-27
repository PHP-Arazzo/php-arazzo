<?php

declare(strict_types=1);

namespace Alama\Arazzo\Cli\Console\Run;

use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Workflow;
use Alama\Arazzo\Contracts\Validation\Error;
use Alama\Arazzo\Document\DocumentInterface;
use Alama\Arazzo\Document\Parser\Exceptions\LoaderException;
use Alama\Arazzo\Document\Parser\Exceptions\ParserException;
use Alama\Arazzo\Runner\Exceptions\AmbiguousStepException;
use Alama\Arazzo\Runner\Exceptions\UnknownStepException;
use Alama\Arazzo\Runner\Preflight\StepPreflightValidator;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Loads an Arazzo document and runs preflight workflow, input, and dependency checks
 * for `arazzo run` before any network request is dispatched.
 *
 * @internal
 */
final class RunPreflight
{
    public function load(
        DocumentInterface $facade,
        string $file,
        OutputInterface $output,
    ): ?ArazzoDocument {
        try {
            $doc = $facade->load($file);
            chdir(dirname($file));

            return $doc;
        } catch (LoaderException|ParserException $e) {
            $output->writeln('<error>PARSE ERROR</error> '.$e->getMessage());

            return null;
        }
    }

    public function resolveWorkflow(
        ArazzoDocument $document,
        ?string $stepId,
        ?string $workflowId,
        OutputInterface $output,
    ): ?Workflow {
        if ($stepId !== null) {
            try {
                return (new StepPreflightValidator())->resolveTargetWorkflow($document, $stepId, $workflowId);
            } catch (UnknownStepException|AmbiguousStepException $e) {
                $output->writeln('<error>'.$e->getMessage().'</error>');

                return null;
            }
        }

        $workflow = $workflowId !== null
            ? $document->findWorkflow($workflowId)
            : $document->firstWorkflow();

        if ($workflow === null) {
            $output->writeln('<error>'.sprintf("unknown workflow '%s'", (string) $workflowId).'</error>');

            return null;
        }

        return $workflow;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function resolveInputs(
        DocumentInterface $facade,
        ArazzoDocument $doc,
        Workflow $workflow,
        InputInterface $input,
        OutputInterface $output,
    ): ?array {
        /** @var string|null $rawInput */
        $rawInput = $input->getOption('input');
        $inputs = $this->parseInputs($rawInput, $output);
        if ($inputs === null) {
            return null;
        }

        $preflightResult = $facade->validateInputs($doc, (string) $workflow->workflowId, $inputs);
        if (!$preflightResult->isValid()) {
            foreach ($preflightResult->errors as $error) {
                $output->writeln('<error>'.$error->message.'</error>');
            }

            return null;
        }

        $inputs = $preflightResult->inputs;
        /** @var string|null $stepId */
        $stepId = $input->getOption('step');
        $noDeps = (bool) $input->getOption('no-deps');

        if ($stepId !== null && $noDeps && !$this->checkDependencies($doc, $stepId, (string) $workflow->workflowId, $inputs, $output)) {
            return null;
        }

        return $inputs;
    }

    /**
     * @param  array<string, mixed>  $inputs
     */
    private function checkDependencies(
        ArazzoDocument $doc,
        string $stepId,
        string $workflowId,
        array $inputs,
        OutputInterface $output,
    ): bool {
        /** @var list<Error> $depErrors */
        $depErrors = [];
        (new StepPreflightValidator())->validateStepDependencies($doc, $stepId, $workflowId, $inputs, $depErrors);

        if ($depErrors === []) {
            return true;
        }

        foreach ($depErrors as $error) {
            $output->writeln('<error>'.$error->message.'</error>');
        }
        $output->writeln('<comment>Supply the value via --input or remove --no-deps.</comment>');

        return false;
    }

    /**
     * @return array<string, mixed>|null
     */
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
}
