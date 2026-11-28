<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Stock de vides d'un dépôt. Modifié uniquement par PackagingService. */
class PackagingLevel extends Model
{
    use BelongsToTenant;

    protected $fillable = ['depot_id', 'packaging_type_id', 'quantity'];

    protected $attributes = ['quantity' => 0];

    protected function casts(): array
    {
        return ['quantity' => 'integer'];
    }

    public function depot(): BelongsTo
    {
        return $this->belongsTo(Depot::class);
    }

    public function packagingType(): BelongsTo
    {
        return $this->belongsTo(PackagingType::class);
    }
}
