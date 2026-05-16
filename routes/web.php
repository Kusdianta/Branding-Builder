<?php

declare(strict_types=1);

use App\Http\Controllers\AuditController;
use App\Http\Controllers\Auth\GoogleAuthController;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::get('/health', fn () => response('ok', 200))->name('health');

Volt::route('/', 'brand-audit-wizard')->name('home');

// BB81 — Google OAuth entry points.
Route::get('/auth/google', [GoogleAuthController::class, 'redirect'])->name('auth.google.redirect');
Route::get('/auth/google/callback', [GoogleAuthController::class, 'callback'])->name('auth.google.callback');
Route::post('/auth/logout', [GoogleAuthController::class, 'logout'])->name('auth.logout');

// BB83 — Per-user audit history (auth required).
Route::middleware('auth')->group(function () {
    Volt::route('/audits', 'audits-index')->name('audits.index');
});

Volt::route('/audit/{token}', 'brand-audit-wizard')->name('audit.show');

Route::get('/audit/{token}/status', [AuditController::class, 'status'])->name('audit.status');

Route::post('/audit/{token}/kit', [AuditController::class, 'generateKit'])->name('audit.kit.generate');

// BB59: retry a single gather step + re-flow scoring/PDF.
Route::post('/audit/{token}/retry-step', [AuditController::class, 'retryStep'])
    ->name('audit.retry-step');

Route::get('/audit/{token}/kit/download', [AuditController::class, 'downloadKit'])->name('audit.kit.download');
