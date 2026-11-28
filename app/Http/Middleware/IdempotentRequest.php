<?php

namespace App\Http\Middleware;

use App\Support\CurrentTenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Écritures API : en-tête « Idempotency-Key » obligatoire. Une requête rejouée avec la
 * même clé renvoie la réponse d'origine sans rien enregistrer une seconde fois.
 */
class IdempotentRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = trim((string) $request->header('Idempotency-Key'));
        if ($key === '' || strlen($key) > 100) {
            return response()->json(['error' => 'En-tête Idempotency-Key obligatoire (100 caractères au plus).'], 400);
        }

        $tenantId = app(CurrentTenant::class)->id();
        $find = fn () => DB::table('api_idempotency_keys')->where('tenant_id', $tenantId)->where('key', $key)->first();

        if ($stored = $find()) {
            return response($stored->response, $stored->status, ['Content-Type' => 'application/json', 'Idempotent-Replay' => 'true']);
        }

        $response = $next($request);

        // Seules les réussites sont mémorisées : une requête refusée peut être corrigée et renvoyée.
        if ($response->getStatusCode() < 300) {
            DB::table('api_idempotency_keys')->insertOrIgnore([
                'tenant_id' => $tenantId,
                'key' => $key,
                'route' => substr((string) $request->route()?->getName(), 0, 100),
                'status' => $response->getStatusCode(),
                'response' => $response->getContent(),
                'created_at' => now(),
            ]);
        }

        return $response;
    }
}
