<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Un site physique de stockage et de vente (lancement avec un seul dépôt,
 * architecture prête pour plusieurs sites).
 */
class Depot extends Model
{
    use Auditable, BelongsToTenant, HasFactory;

    protected $attributes = ['is_active' => true];

    protected $fillable = ['name', 'code', 'address', 'phone', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
