<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Emballages à échanger pour 1 conditionnement vendu. */
class ProductUnitPackaging extends Model
{
    use Auditable, BelongsToTenant;

    protected $fillable = ['packaging_type_id', 'quantity'];

    protected function casts(): array
    {
        return ['quantity' => 'integer'];
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(ProductUnit::class, 'product_unit_id');
    }

    public function packagingType(): BelongsTo
    {
        return $this->belongsTo(PackagingType::class);
    }
}
