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
    Route::get('/events/{event}/summary', [EventSummaryController::class, 'summary']);
    Route::get('/events/{event}/top-stores', [EventSummaryController::class, 'topStores']);
});
