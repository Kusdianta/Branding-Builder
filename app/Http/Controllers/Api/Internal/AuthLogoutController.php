<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * BB05 — inbound endpoint the Hub calls to log a user out of this spoke as
 * part of a platform-wide logout (SSO06). Deletes every DB session belonging
 * to the local user mapped from hub_user_id. Bearer-guarded (hub.users).
 */
class AuthLogoutController extends Controller
{
    public function logout(Request $request): JsonResponse
    {
        $hubUserId = (string) $request->input('hub_user_id', '');

        if ($hubUserId === '') {
            return response()->json(['error' => 'hub_user_id is required'], 422);
        }

        $user = User::where('hub_user_id', $hubUserId)->first();
        $deleted = 0;

        if ($user !== null) {
            $deleted = DB::table('sessions')->where('user_id', $user->getKey())->delete();
        }

        return response()->json(['logged_out' => true, 'sessions_deleted' => $deleted]);
    }
}
