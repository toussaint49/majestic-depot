<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // API : une règle métier refusée renvoie 422 avec le message affichable.
        $exceptions->render(function (\Throwable $e, \Illuminate\Http\Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }
            $business = [
                \App\Exceptions\SaleException::class, \App\Exceptions\PurchaseException::class,
                \App\Exceptions\CashSessionException::class, \App\Exceptions\InsufficientStockException::class,
                \App\Exceptions\MissingPackagingException::class, \App\Exceptions\InsufficientPackagingException::class,
                \InvalidArgumentException::class,
            ];
            foreach ($business as $class) {
                if ($e instanceof $class) {
                    return response()->json(['error' => $e->getMessage()], 422);
                }
            }
            $missing = $e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException ? $e : $e->getPrevious();
            if ($missing instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) {
                return response()->json(['error' => 'Élément introuvable : '.class_basename($missing->getModel()).' '.implode(',', $missing->getIds())], 404);
            }

            return null;
        });
    })->create();
