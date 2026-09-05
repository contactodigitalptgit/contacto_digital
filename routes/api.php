<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\EventSummaryController;
use Illuminate\Support\Facades\Route;

// Token API for the client-facing mobile app (Flutter) — see
// docs/PLANO_DE_PERFORMANCE_SINCRONIZACAO.md, "app Flutter, cliente
// acompanhar o evento". Nothing here is used by the web app itself.
Route::post('/login', [AuthController::class, 'login']);

Route::middleware(['auth:sanctum', 'active.client.api'])->group(function (): void {
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::get('/events', [EventSummaryController::class, 'index']);
    Route::get('/events/{event}/dashboard', [EventSummaryController::class, 'dashboard']);
    Route::get('/events/{event}/summary', [EventSummaryController::class, 'summary']);
    Route::get('/events/{event}/top-stores', [EventSummaryController::class, 'topStores']);
    Route::get('/events/{event}/configuration', [EventSummaryController::class, 'configuration']);
    Route::get('/events/{event}/filters', [EventSummaryController::class, 'filters']);
    Route::get('/events/{event}/products', [EventSummaryController::class, 'products']);
    Route::get('/events/{event}/payments', [EventSummaryController::class, 'payments']);
    Route::get('/events/{event}/zones', [EventSummaryController::class, 'zones']);
    Route::get('/events/{event}/performance', [EventSummaryController::class, 'performance']);
    Route::get('/events/{event}/comparison', [EventSummaryController::class, 'comparison']);
});
