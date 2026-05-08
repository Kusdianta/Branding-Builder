<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\BrandKitController;
use App\Http\Controllers\KolController;

Route::get('/',          [BrandKitController::class, 'index'])->name('home');
Route::post('/generate', [BrandKitController::class, 'generate'])->name('generate');
Route::get('/loading',   [BrandKitController::class, 'loading'])->name('loading');
Route::get('/status',    [BrandKitController::class, 'status'])->name('status');
Route::get('/results',   [BrandKitController::class, 'results'])->name('results');
Route::get('/download',  [BrandKitController::class, 'download'])->name('download');

Route::get('/kol',          [KolController::class, 'index']);
Route::post('/kol/search',  [KolController::class, 'search']);
Route::get('/kol/status',   [KolController::class, 'status']);
Route::get('/kol/results',  [KolController::class, 'results']);
Route::post('/kol/export', [KolController::class, 'export']);