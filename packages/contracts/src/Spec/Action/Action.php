<?php

declare(strict_types=1);

namespace Alama\Arazzo\Contracts\Spec\Action;

use Alama\Arazzo\Contracts\Spec\Enum\ActionKind;

abstract readonly class Action
{
    public function __construct(
        public string $name,
        public ActionKind $kind,
    ) {}

    public function isEnd(): bool
    {
        return $this->kind === ActionKind::End;
    }

    public function isGoto(): bool
    {
        return $this->kind === ActionKind::Goto;
    }

    public function isRetry(): bool
    {
        return $this->kind === ActionKind::Retry;
    }

    public function isInvoke(): bool
    {
        return $this->kind === ActionKind::Invoke;
    }
}
