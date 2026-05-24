<?php

declare(strict_types=1);

use App\Http\Controllers\Api\Internal\AuthLogoutController;
use App\Http\Controllers\Api\Internal\UsersApiController;
use Illuminate\Support\Facades\Route;

/*
| BB84 — internal API consumed by the Hub Filament dashboard. Bearer-token
| guarded via the HubUsersApiKey middleware (alias 'hub.users'). Public web
| traffic never reaches this surface.
*/

Route::prefix('internal')->middleware('hub.users')->group(function (): void {
    Route::get('/users',                       [UsersApiController::class, 'index']);
    Route::get('/users/{id}',                  [UsersApiController::class, 'show']);
    Route::post('/users/{id}/credits/adjust',  [UsersApiController::class, 'adjustCredits']);
    Route::delete('/users/{id}',               [UsersApiController::class, 'destroy']);

    // BB05 — Hub broadcasts a platform-wide logout to this spoke.
    Route::post('/auth/logout',                [AuthLogoutController::class, 'logout']);
});
