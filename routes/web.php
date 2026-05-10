<?php

declare(strict_types=1);

use App\Http\Controllers\AuditController;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::get('/health', fn () => response('ok', 200))->name('health');

Volt::route('/', 'brand-audit-wizard')->name('home');

Volt::route('/audit/{token}', 'brand-audit-wizard')->name('audit.show');

Route::get('/audit/{token}/status', [AuditController::class, 'status'])->name('audit.status');
