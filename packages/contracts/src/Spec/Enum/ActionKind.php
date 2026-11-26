<?php

declare(strict_types=1);

namespace Alama\Arazzo\Contracts\Spec\Enum;

enum ActionKind: string
{
    case Goto = 'goto';
    case End = 'end';
    case Retry = 'retry';
    case Invoke = 'invoke';

    public function isSpec(): bool
    {
        return match ($this) {
            self::Goto, self::End, self::Retry => true,
            default => false,
        };
    }

    public function isExtension(): bool
    {
        return !$this->isSpec();
    }

    public static function tryFromRaw(string $value): ?self
    {
        $normalized = str_starts_with($value, 'x-') ? substr($value, 2) : $value;

        return self::tryFrom($normalized);
    }
}
