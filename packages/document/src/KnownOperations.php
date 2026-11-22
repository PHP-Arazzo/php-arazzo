<?php

declare(strict_types=1);

namespace Alama\Arazzo\Document;

use Alama\Arazzo\Contracts\Data\KnownOperations as ContractKnownOperations;

if (!class_exists(KnownOperations::class, false)) {
    class_alias(ContractKnownOperations::class, KnownOperations::class);
}
