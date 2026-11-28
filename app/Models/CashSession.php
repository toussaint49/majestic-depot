<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CashSession extends Model
{
    use Auditable, BelongsToTenant;

    public const OPEN = 'open';
    public const CLOSED = 'closed';
    public const VALIDATED = 'validated';

    public const STATUSES = [
        self::OPEN => 'Ouverte',
        self::CLOSED => 'Clôturée (à valider)',
        self::VALIDATED => 'Validée',
    ];

    protected $attributes = ['status' => self::OPEN];

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'opening_float' => 'integer',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'validated_at' => 'datetime',
        ];
    }

    public function depot(): BelongsTo
    {
        return $this->belongsTo(Depot::class);
    }

    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function validator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validated_by');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(AccountTransaction::class);
    }

    public function counts(): HasMany
    {
        return $this->hasMany(CashSessionCount::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(CashMovement::class);
    }

    public function isOpen(): bool
    {
        return $this->status === self::OPEN;
    }

    public function isValidated(): bool
    {
        return $this->status === self::VALIDATED;
    }
}
