<?php

namespace App\Support;

use App\Models\Product;

/**
 * Conversions entre unité de base et conditionnements commerciaux.
 */
final class Quantity
{
    /** Convertit une quantité saisie dans un conditionnement en unités de base. */
    public static function toBase(int $quantity, int $factor): int
    {
        return $quantity * max(1, $factor);
    }

    /**
     * Affiche une quantité de base en équivalences, du plus grand conditionnement
     * au plus petit. Ex. 30 bouteilles, casier de 12 → « 2 Casier + 6 Bouteille ».
     */
    public static function format(Product $product, int $baseQuantity): string
    {
        $sign = $baseQuantity < 0 ? '−' : '';
        $rest = abs($baseQuantity);

        $units = $product->relationLoaded('units') ? $product->units : $product->units()->get();
        $units = $units->where('is_active', true)->where('factor', '>', 1)->sortByDesc('factor');

        $parts = [];
        foreach ($units as $unit) {
            $n = intdiv($rest, $unit->factor);
            if ($n > 0) {
                $parts[] = "$n {$unit->name}";
                $rest -= $n * $unit->factor;
            }
        }
        if ($rest > 0 || $parts === []) {
            $parts[] = "$rest {$product->base_unit_name}";
        }

        return $sign.implode(' + ', $parts);
    }

    public static function money(int|float|string|null $amount): string
    {
        return number_format((float) $amount, 0, ',', ' ').' FCFA';
    }
}
