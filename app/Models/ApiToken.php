<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ApiToken extends Model
{
    use Auditable, BelongsToTenant;

    public const PREFIX = 'mjd_';

    protected $guarded = ['id', 'tenant_id', 'token_hash'];

    protected $hidden = ['token_hash'];

    protected array $auditExclude = ['token_hash', 'last_used_at'];

    protected function casts(): array
    {
        return ['last_used_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Crée un jeton pour l'utilisateur dans l'entreprise active. @return array{0: ApiToken, 1: string} [jeton, valeur en clair] */
    public static function issue(User $user, string $name): array
    {
        $plain = self::PREFIX.Str::random(48);
        $token = new self(['user_id' => $user->id, 'name' => $name, 'hint' => substr($plain, -6)]);
        $token->token_hash = hash('sha256', $plain);
        $token->save();

        return [$token, $plain];
    }

    /** Recherche hors contexte d'entreprise (avant authentification). */
    public static function findActive(string $plain): ?self
    {
        if (! str_starts_with($plain, self::PREFIX)) {
            return null;
        }

        return self::withoutGlobalScope(TenantScope::class)
            ->where('token_hash', hash('sha256', $plain))->whereNull('revoked_at')->first();
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }
}
