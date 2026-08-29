<?php

use App\Http\Controllers\Admin\ClientController;
use App\Http\Controllers\Admin\EventController;
use App\Http\Controllers\Admin\EventDashboardConfigurationController;
use App\Http\Controllers\Admin\EventZoneSoftIntegrationController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EventDashboardController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

Route::get('/manifest.webmanifest', function () {
    $manifestPath = public_path('build/manifest.webmanifest');

    abort_unless(File::exists($manifestPath), 404);

    return response()->file($manifestPath, [
        'Content-Type' => 'application/manifest+json',
        'Cache-Control' => 'public, max-age=300',
    ]);
});

Route::redirect('/manifest.json', '/manifest.webmanifest', 301);

Route::get('/sw.js', function () {
    $legacyWorkerPath = resource_path('pwa/legacy-sw.js');

    abort_unless(File::exists($legacyWorkerPath), 404);

    return response()->file($legacyWorkerPath, [
        'Content-Type' => 'application/javascript; charset=UTF-8',
        'Cache-Control' => 'no-cache, no-store, must-revalidate',
        'Service-Worker-Allowed' => '/',
    ]);
});

Route::get('/', function () {
    return auth()->check()
        ? to_route('dashboard')
        : to_route('login');
});

Route::get('/dashboard', DashboardController::class)
    ->middleware(['auth', 'active.client'])
    ->name('dashboard');

Route::middleware(['auth', 'admin'])->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

Route::middleware(['auth', 'active.client'])->group(function () {
    Route::get('/events/{event}/dashboard', [EventDashboardController::class, 'show'])
        ->name('events.dashboard');
    Route::get('/events/{event}/produtos', [EventDashboardController::class, 'products'])
        ->name('events.products');
    Route::get('/events/{event}/pagamentos', [EventDashboardController::class, 'payments'])
        ->name('events.payments');
    Route::get('/events/{event}/zonas', [EventDashboardController::class, 'zones'])
        ->name('events.zones');
    Route::get('/events/{event}/performance', [EventDashboardController::class, 'performance'])
        ->name('events.performance');
    Route::get('/events/{event}/comparar', [EventDashboardController::class, 'comparison'])
        ->name('events.comparison');
});

Route::middleware(['auth', 'active.client', 'admin'])
    ->prefix('admin')
    ->as('admin.')
    ->group(function (): void {
        Route::patch('clients/{client}/status', [ClientController::class, 'toggleStatus'])
            ->name('clients.toggle-status');
        Route::get('clients/{client}/dashboard', [ClientController::class, 'dashboard'])
            ->name('clients.dashboard');
        Route::patch('events/{event}/status', [EventController::class, 'toggleStatus'])
            ->name('events.toggle-status');
        Route::get('events/{event}/dashboard', [EventDashboardController::class, 'preview'])
            ->name('events.dashboard');
        Route::get('events/{event}/produtos', [EventDashboardController::class, 'previewProducts'])
            ->name('events.products');
        Route::get('events/{event}/pagamentos', [EventDashboardController::class, 'previewPayments'])
            ->name('events.payments');
        Route::get('events/{event}/zonas', [EventDashboardController::class, 'previewZones'])
            ->name('events.zones');
        Route::get('events/{event}/performance', [EventDashboardController::class, 'previewPerformance'])
            ->name('events.performance');
        Route::get('events/{event}/comparar', [EventDashboardController::class, 'previewComparison'])
            ->name('events.comparison');
        Route::get('events/{event}/dashboard-configuration/edit', [EventDashboardConfigurationController::class, 'edit'])
            ->name('events.dashboard-configuration.edit');
        Route::patch('events/{event}/dashboard-configuration', [EventDashboardConfigurationController::class, 'update'])
            ->name('events.dashboard-configuration.update');
        Route::get('events/{event}/gerir-tpa', [EventZoneSoftIntegrationController::class, 'manageTpas'])
            ->name('events.tpas.manage');
        Route::post('events/{event}/reports', [EventController::class, 'storeReport'])
            ->name('events.reports.store');
        Route::get('events/{event}/integrations', [EventZoneSoftIntegrationController::class, 'show'])
            ->name('events.integrations.show');
        Route::post('events/{event}/integrations/application', [EventZoneSoftIntegrationController::class, 'saveApplication'])
            ->name('events.integrations.application.save');
        Route::post('events/{event}/integrations/discover-stores', [EventZoneSoftIntegrationController::class, 'discoverStores'])
            ->name('events.integrations.discover-stores');
        Route::post('events/{event}/integrations/machines/validate-stores', [EventZoneSoftIntegrationController::class, 'validateAllMachines'])
            ->name('events.integrations.machines.validate-all');
        Route::post('events/{event}/integrations/machines', [EventZoneSoftIntegrationController::class, 'storeMachine'])
            ->name('events.integrations.machines.store');
        Route::put('events/{event}/integrations/machines/{machine}', [EventZoneSoftIntegrationController::class, 'updateMachine'])
            ->name('events.integrations.machines.update');
        Route::delete('events/{event}/integrations/machines/{machine}', [EventZoneSoftIntegrationController::class, 'destroyMachine'])
            ->name('events.integrations.machines.destroy');
        Route::resource('clients', ClientController::class);
        Route::resource('events', EventController::class);
    });

require __DIR__.'/auth.php';
