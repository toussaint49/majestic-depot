<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Conditionnement commercial d'un produit (Bouteille ×1, Casier ×12…) et son prix de vente.
 */
class ProductUnit extends Model
{
    use Auditable, BelongsToTenant;

    protected $attributes = ['is_active' => true, 'price' => 0];

    protected $fillable = ['name', 'factor', 'price', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'factor' => 'integer', 'price' => 'integer'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** Emballages à échanger pour 1 unité de ce conditionnement. */
    public function packagings(): HasMany
    {
        return $this->hasMany(ProductUnitPackaging::class);
    }
}
