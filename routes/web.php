<?php

use App\Http\Controllers\Admin\ClientController;
use App\Http\Controllers\Admin\ClientZoneSoftIntegrationController;
use App\Http\Controllers\Admin\EventController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EventDashboardController;
use App\Http\Controllers\ProfileController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

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
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
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
});

Route::middleware(['auth', 'active.client', 'admin'])
    ->prefix('admin')
    ->as('admin.')
    ->group(function (): void {
        Route::patch('clients/{client}/status', [ClientController::class, 'toggleStatus'])
            ->name('clients.toggle-status');
        Route::get('clients/{client}/dashboard', [ClientController::class, 'dashboard'])
            ->name('clients.dashboard');
        Route::get('clients/{client}/integrations', [ClientZoneSoftIntegrationController::class, 'show'])
            ->name('clients.integrations.show');
        Route::post('clients/{client}/integrations/application', [ClientZoneSoftIntegrationController::class, 'saveApplication'])
            ->name('clients.integrations.application.save');
        Route::post('clients/{client}/integrations/discover-stores', [ClientZoneSoftIntegrationController::class, 'discoverStores'])
            ->name('clients.integrations.discover-stores');
        Route::post('clients/{client}/integrations/machines', [ClientZoneSoftIntegrationController::class, 'storeMachine'])
            ->name('clients.integrations.machines.store');
        Route::put('clients/{client}/integrations/machines/{machine}', [ClientZoneSoftIntegrationController::class, 'updateMachine'])
            ->name('clients.integrations.machines.update');
        Route::delete('clients/{client}/integrations/machines/{machine}', [ClientZoneSoftIntegrationController::class, 'destroyMachine'])
            ->name('clients.integrations.machines.destroy');
        Route::patch('events/{event}/status', [EventController::class, 'toggleStatus'])
            ->name('events.toggle-status');
        Route::get('events/{event}/dashboard', [EventDashboardController::class, 'preview'])
            ->name('events.dashboard');
        Route::post('events/{event}/reports', [EventController::class, 'storeReport'])
            ->name('events.reports.store');
        Route::resource('clients', ClientController::class);
        Route::resource('events', EventController::class);
    });

require __DIR__.'/auth.php';
