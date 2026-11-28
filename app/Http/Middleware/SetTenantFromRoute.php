<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Support\CurrentTenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pour les routes hors panneau (/t/{tenant}/…) : vérifie l'appartenance de
 * l'utilisateur à l'entreprise, puis l'active pour les scopes et les permissions.
 */
class SetTenantFromRoute
{
    public function __construct(private CurrentTenant $currentTenant) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = Tenant::where('slug', (string) $request->route('tenant'))->where('is_active', true)->first();

        abort_unless($tenant && $request->user()?->belongsToTenant($tenant), 404);
        $this->currentTenant->set($tenant);

        return $next($request);
    }
}
