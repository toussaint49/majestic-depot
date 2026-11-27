<?php

namespace App\Exceptions;

use App\Models\Product;
use App\Support\Quantity;
use RuntimeException;

class InsufficientStockException extends RuntimeException
{
    public function __construct(public readonly Product $product, public readonly int $available, public readonly int $requested)
    {
        parent::__construct(sprintf(
            'Stock insuffisant pour « %s » : %s disponible(s), %s demandé(s).',
            $product->name,
            Quantity::format($product, $available),
            Quantity::format($product, $requested),
        ));
    }
}
