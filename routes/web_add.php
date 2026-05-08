<?php
// Tambahkan ke routes/web.php
use App\Http\Controllers\KolController;

Route::get('/kol', [KolController::class, 'index']);
Route::post('/kol/search', [KolController::class, 'search']);
Route::get('/kol/status', [KolController::class, 'status']);
Route::get('/kol/results', [KolController::class, 'results']);
