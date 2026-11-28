<?php

namespace App\Exceptions;

use App\Models\PackagingType;
use RuntimeException;

class InsufficientPackagingException extends RuntimeException
{
    public function __construct(public readonly PackagingType $packaging, public readonly int $available, public readonly int $requested)
    {
        parent::__construct("Stock de vides insuffisant pour « {$packaging->name} » : $available disponible(s), $requested demandé(s).");
    }
}
