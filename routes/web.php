<?php

use App\Http\Controllers\Admin\ClientController;
use App\Http\Controllers\Admin\EventController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProfileController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

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

Route::middleware(['auth', 'active.client'])->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
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
        Route::resource('clients', ClientController::class);
        Route::resource('events', EventController::class);
    });

require __DIR__.'/auth.php';
