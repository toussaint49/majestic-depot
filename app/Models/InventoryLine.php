<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryLine extends Model
{
    use BelongsToTenant;

    protected $fillable = ['product_id', 'counted_quantity'];

    protected function casts(): array
    {
        return [
            'counted_quantity' => 'integer',
            'expected_quantity' => 'integer',
            'difference' => 'integer',
            'unit_cost' => 'decimal:4',
        ];
    }

    public function inventory(): BelongsTo
    {
        return $this->belongsTo(Inventory::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
