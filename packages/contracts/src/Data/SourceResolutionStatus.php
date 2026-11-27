<?php

declare(strict_types=1);

namespace Alama\Arazzo\Contracts\Data;

enum SourceResolutionStatus: string
{
    case Ok = 'ok';
    case Unreadable = 'unreadable';
    case Unparseable = 'unparseable';
    case TypeMismatch = 'type_mismatch';
    case TypeInferenceFailed = 'type_inference_failed';
}
