<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PlatformHealthChecker;
use Illuminate\Http\JsonResponse;

/**
 * BB105 Part 3 — GET /api/health/platform.
 *
 * Auth-gated so service status isn't exposed publicly. The wizard
 * calls the underlying PlatformHealthChecker service directly (no
 * HTTP loopback) — this controller exists for operator debugging
 * via browser/curl with a signed-in session.
 */
final class HealthController extends Controller
{
    public function platform(PlatformHealthChecker $checker): JsonResponse
    {
        return response()->json($checker->check());
    }
}
