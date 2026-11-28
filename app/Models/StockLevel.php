<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Solde courant d'un produit dans un dépôt. Uniquement modifié par StockService ;
 * il doit toujours être égal à la somme des mouvements.
 */
class StockLevel extends Model
{
    use BelongsToTenant;

    protected $fillable = ['depot_id', 'product_id', 'quantity', 'avg_cost'];

    protected $attributes = ['quantity' => 0, 'avg_cost' => 0];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'avg_cost' => 'decimal:4'];
    }

    public function depot(): BelongsTo
    {
        return $this->belongsTo(Depot::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function value(): float
    {
        return round($this->quantity * (float) $this->avg_cost);
    }
}
