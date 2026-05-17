<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\HandleCheckers\InstagramHandleChecker;
use App\Services\HandleCheckers\TikTokHandleChecker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * BB100/BB101 — wizard Step 3 handle availability endpoints.
 *
 * Endpoints sit on routes/web.php (not routes/api.php) because the
 * wizard is a public anonymous flow — auth would block legitimate
 * pre-OAuth checks. CSRF + session middleware come from the default
 * 'web' group. Throttle is the only abuse mitigation.
 */
final class HandleCheckController extends Controller
{
    public function instagram(Request $request, InstagramHandleChecker $checker): JsonResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9._]+$/'],
        ]);

        $result = $checker->check($data['username']);

        return response()->json($result->toArray());
    }

    public function tiktok(Request $request, TikTokHandleChecker $checker): JsonResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'max:24', 'regex:/^[A-Za-z0-9._]+$/'],
        ]);

        $result = $checker->check($data['username']);

        return response()->json($result->toArray());
    }
}
