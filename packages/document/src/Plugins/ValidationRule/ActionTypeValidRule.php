<?php

declare(strict_types=1);

namespace Alama\Arazzo\Document\Plugins\ValidationRule;

use Alama\Arazzo\Contracts\Attributes\AsValidationRulePlugin;
use Alama\Arazzo\Contracts\Interfaces\ValidationRuleInterface;
use Alama\Arazzo\Contracts\Spec\Action\Action;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Spec\Reusable;
use Alama\Arazzo\Contracts\Spec\Workflow;
use Alama\Arazzo\Contracts\Validation\ErrorCollector;
use Alama\Arazzo\Contracts\Validation\SymbolTable;

/**
 * @internal stays out of the advertised contract; not part of the public API surface
 */
#[AsValidationRulePlugin]
final class ActionTypeValidRule implements ValidationRuleInterface
{
    public function __construct(
        private readonly bool $conformance = false,
    ) {}

    public function code(): string
    {
        return 'action.type_valid';
    }

    public function check(ArazzoDocument $doc, SymbolTable $symbols, ErrorCollector $errors): void
    {
        $isConformance = $this->conformance || $symbols->conformance;
        if (!$isConformance) {
            return;
        }

        foreach ($doc->workflows as $wi => $w) {
            $this->checkWorkflow($w, (int) $wi, $errors);
        }

        foreach ($doc->components->successActions as $name => $action) {
            $this->checkAction($action, $errors, "/components/successActions/{$name}");
        }
        foreach ($doc->components->failureActions as $name => $action) {
            $this->checkAction($action, $errors, "/components/failureActions/{$name}");
        }
    }

    private function checkWorkflow(Workflow $w, int $wi, ErrorCollector $errors): void
    {
        foreach ($w->steps as $si => $s) {
            foreach ($s->flow->onSuccess as $ai => $action) {
                $this->checkAction($action, $errors, "/workflows/{$wi}/steps/{$si}/onSuccess/{$ai}");
            }
            foreach ($s->flow->onFailure as $ai => $action) {
                $this->checkAction($action, $errors, "/workflows/{$wi}/steps/{$si}/onFailure/{$ai}");
            }
        }

        foreach ($w->successActions as $ai => $action) {
            $this->checkAction($action, $errors, "/workflows/{$wi}/successActions/{$ai}");
        }
        foreach ($w->failureActions as $ai => $action) {
            $this->checkAction($action, $errors, "/workflows/{$wi}/failureActions/{$ai}");
        }
    }

    private function checkAction(mixed $action, ErrorCollector $errors, string $base): void
    {
        if ($action instanceof Reusable || !$action instanceof Action) {
            return;
        }

        if ($action->kind->isExtension()) {
            $raw = $action->rawType ?? $action->kind->value;
            $errors->error(
                $this->code(),
                "Action type '{$raw}' is a non-spec extension and not allowed in strict conformance mode.",
                "{$base}/type",
            );
        }
    }
}
