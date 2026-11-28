<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use App\Models\Tenant;
use App\Support\CurrentTenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * API : « Authorization: Bearer mjd_… ». Le jeton détermine l'entreprise et
 * l'utilisateur au nom duquel les opérations sont faites (permissions comprises).
 */
class AuthenticateApiToken
{
    public function __construct(private CurrentTenant $currentTenant) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = ApiToken::findActive((string) $request->bearerToken());
        $tenant = $token ? Tenant::whereKey($token->tenant_id)->where('is_active', true)->first() : null;
        $user = $token?->user;

        if (! $token || ! $tenant || ! $user || ! $user->is_active || ! $user->belongsToTenant($tenant)) {
            return response()->json(['error' => 'Jeton invalide ou révoqué.'], 401);
        }

        $this->currentTenant->set($tenant);
        Auth::setUser($user);
        $token->forceFill(['last_used_at' => now()])->saveQuietly();

        return $next($request);
    }
}
