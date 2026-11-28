<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Caisse espèces, MTN Mobile Money, Orange Money, banque… */
class PaymentAccount extends Model
{
    use Auditable, BelongsToTenant;

    public const KINDS = [
        'cash' => 'Espèces',
        'mtn_momo' => 'MTN Mobile Money',
        'orange_money' => 'Orange Money',
        'bank' => 'Banque',
        'other' => 'Autre',
    ];

    /** Comptes créés pour chaque nouvelle entreprise. */
    public const DEFAULTS = [
        ['name' => 'Caisse espèces', 'kind' => 'cash'],
        ['name' => 'MTN Mobile Money', 'kind' => 'mtn_momo'],
        ['name' => 'Orange Money', 'kind' => 'orange_money'],
    ];

    protected $attributes = ['is_active' => true];

    protected $fillable = ['name', 'kind', 'number', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(AccountTransaction::class);
    }

    public function balance(): int
    {
        return (int) $this->transactions()->sum('amount');
    }
}
