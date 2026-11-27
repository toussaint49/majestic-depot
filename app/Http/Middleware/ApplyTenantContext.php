<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Support\CurrentTenant;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Active l'entreprise choisie dans le panneau (déjà vérifiée par Filament via
 * User::canAccessTenant) pour les scopes Eloquent et les permissions.
 */
class ApplyTenantContext
{
    public function __construct(private CurrentTenant $currentTenant) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = Filament::getTenant();

        if ($tenant instanceof Tenant) {
            abort_unless($request->user()?->belongsToTenant($tenant), 403);
            $this->currentTenant->set($tenant);
        }

        return $next($request);
    }
}
