<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use App\Support\CurrentTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

/**
 * Journal d'audit : en écriture seule. Une entrée ne peut être ni modifiée ni supprimée.
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
        static::updating(fn () => throw new LogicException("Le journal d'audit n'est pas modifiable."));
        static::deleting(fn () => throw new LogicException("Le journal d'audit n'est pas supprimable."));
    }

    public static function record(string $action, ?Model $subject = null, array $old = [], array $new = [], ?int $tenantId = null): self
    {
        $request = app()->runningInConsole() ? null : request();

        $tenantId ??= $subject?->getAttribute('tenant_id')
            ?? ($subject instanceof Tenant ? $subject->getKey() : null)
            ?? app(CurrentTenant::class)->id();

        return static::query()->create([
            'tenant_id' => $tenantId,
            'user_id' => auth()->id(),
            'action' => $action,
            'auditable_type' => $subject?->getMorphClass(),
            'auditable_id' => $subject?->getKey(),
            'old_values' => $old ?: null,
            'new_values' => $new ?: null,
            'ip_address' => $request?->ip(),
            'user_agent' => $request ? substr((string) $request->userAgent(), 0, 255) : null,
        ]);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }
}
