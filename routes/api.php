<?php

use App\Http\Controllers\Api\V1Controller;
use App\Http\Middleware\AuthenticateApiToken;
use App\Http\Middleware\IdempotentRequest;
use Illuminate\Support\Facades\Route;

/*
 * API v1 — utilisée par Claude pour enregistrer factures et fiches de vente.
 * Authentification : « Authorization: Bearer mjd_… » (jeton créé dans Paramètres › Accès API).
 * Écritures : en-tête « Idempotency-Key » obligatoire.
 */
Route::prefix('v1')->middleware([AuthenticateApiToken::class, 'throttle:120,1'])->name('api.')->group(function () {
    Route::get('catalogue', [V1Controller::class, 'catalogue'])->name('catalogue');
    Route::get('stock', [V1Controller::class, 'stock'])->name('stock');
    Route::get('journee', [V1Controller::class, 'day'])->name('day');
    Route::get('caisse', [V1Controller::class, 'cash'])->name('cash');

    Route::middleware(IdempotentRequest::class)->group(function () {
        Route::post('fournisseurs', [V1Controller::class, 'storeSupplier'])->name('suppliers.store');
        Route::post('clients', [V1Controller::class, 'storeCustomer'])->name('customers.store');
        Route::post('achats', [V1Controller::class, 'storePurchase'])->name('purchases.store');
        Route::post('ventes', [V1Controller::class, 'storeSale'])->name('sales.store');
        Route::post('caisse/ouvrir', [V1Controller::class, 'openCash'])->name('cash.open');
        Route::post('caisse/cloturer', [V1Controller::class, 'closeCash'])->name('cash.close');
    });
});
