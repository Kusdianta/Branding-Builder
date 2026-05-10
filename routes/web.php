<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => response('ok', 200))->name('health');

Route::get('/', fn () => response('Branding Builder — Phase 4 rebuild in progress.', 200))
    ->name('home');
