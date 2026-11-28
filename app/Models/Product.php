<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use Auditable, BelongsToTenant;

    protected $attributes = ['is_active' => true, 'base_unit_name' => 'Bouteille', 'alert_threshold' => 0];

    protected $fillable = ['category_id', 'brand_id', 'name', 'reference', 'barcode', 'base_unit_name', 'alert_threshold', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'alert_threshold' => 'integer'];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function units(): HasMany
    {
        return $this->hasMany(ProductUnit::class)->orderBy('factor');
    }

    public function stockLevels(): HasMany
    {
        return $this->hasMany(StockLevel::class);
    }

    public function baseUnit(): ?ProductUnit
    {
        return $this->units->firstWhere('factor', 1);
    }
}
