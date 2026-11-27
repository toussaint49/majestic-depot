<?php

namespace App\Services;

use App\Models\Sequence;
use Illuminate\Support\Facades\DB;

/**
 * Numérotation continue par entreprise (INV-000001, VTE-000001…).
 * Le verrou de ligne garantit qu'aucun numéro n'est attribué deux fois.
 */
class SequenceService
{
    public const DEFAULT_PREFIXES = [
        'inventory' => 'INV-',
        'sale' => 'VTE-',
        'purchase' => 'ACH-',
        'payment' => 'REC-',
        'expense' => 'DEP-',
    ];

    public function next(string $key): string
    {
        return DB::transaction(function () use ($key) {
            $sequence = Sequence::where('key', $key)->lockForUpdate()->first()
                ?? Sequence::create(['key' => $key, 'prefix' => self::DEFAULT_PREFIXES[$key] ?? strtoupper($key).'-', 'next_number' => 1]);

            $number = $sequence->next_number;
            $sequence->increment('next_number');

            return $sequence->prefix.str_pad((string) $number, 6, '0', STR_PAD_LEFT);
        });
    }
}
