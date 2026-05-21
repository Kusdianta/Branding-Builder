<?php

declare(strict_types=1);

use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\AuditController;
use App\Http\Controllers\Auth\GoogleAuthController;
use App\Http\Controllers\HandleCheckController;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::get('/health', fn () => response('ok', 200))->name('health');

// BB105 Part 3 — platform-wide health probe (worker + queue + db + Places key).
// Auth-only so service status isn't exposed publicly.
Route::middleware('auth')
    ->get('/api/health/platform', [HealthController::class, 'platform'])
    ->name('api.health.platform');

Volt::route('/', 'brand-audit-wizard')->name('home');

// Phase 12c.1 BB100/BB101 — wizard Step 3 handle availability checks.
// Public (anonymous wizard flow); abuse-throttled at 30/min/IP.
Route::post('/check-handle/instagram', [HandleCheckController::class, 'instagram'])
    ->middleware('throttle:30,1')
    ->name('check-handle.instagram');
Route::post('/check-handle/tiktok', [HandleCheckController::class, 'tiktok'])
    ->middleware('throttle:30,1')
    ->name('check-handle.tiktok');

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

// BB144 — Places photo proxy. Streams a single outlet photo through
// our backend so the dashboard can render thumbnails without exposing
// the Google Places API key in client HTML. Throttle is intentionally
// loose (60/min) — dashboard renders up to ~6-8 thumbnails per page
// load and the cache hit-rate is high after the first request.
Route::get('/audit/{token}/place-photo/{idx}', [AuditController::class, 'placePhoto'])
    ->whereNumber('idx')
    ->middleware('throttle:60,1')
    ->name('audit.place-photo');
