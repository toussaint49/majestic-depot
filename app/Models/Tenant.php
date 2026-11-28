<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Une entreprise cliente de la plateforme.
 */
class Tenant extends Model
{
    use Auditable, HasFactory;

    protected $attributes = ['is_active' => true, 'default_credit_days' => 14, 'require_cash_session' => true];

    protected $fillable = ['name', 'slug', 'currency', 'timezone', 'phone', 'email', 'address', 'tax_number', 'default_credit_days', 'require_cash_session', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'require_cash_session' => 'boolean'];
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('is_active')->withTimestamps();
    }

    public function depots(): HasMany
    {
        return $this->hasMany(Depot::class);
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }
}
