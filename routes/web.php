<?php

use App\Http\Controllers\SaleDocumentController;
use App\Http\Middleware\SetTenantFromRoute;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/app');

// Le nom « login » est attendu par le middleware auth de Laravel.
Route::redirect('/login', '/app/login')->name('login');

Route::middleware(['auth', SetTenantFromRoute::class])->prefix('t/{tenant}')->group(function () {
    Route::get('ventes/{sale}/ticket', [SaleDocumentController::class, 'ticket'])->whereNumber('sale')->name('sales.ticket');
    Route::get('clients/{customer}/releve', [SaleDocumentController::class, 'statement'])->whereNumber('customer')->name('customers.statement');
});
