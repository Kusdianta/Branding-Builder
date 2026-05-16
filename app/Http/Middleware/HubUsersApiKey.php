<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * BB84 — bearer-token guard for Hub -> branding-builder inbound calls
 * scoped to the /api/internal/users/* surface. Uses a dedicated key
 * (HUB_USERS_API_KEY) rather than the credentials-API key so the two
 * permissions can be rotated independently.
 *
 * Fail-closed: any missing config or mismatched bearer returns 401 with
 * a JSON body — no information leaked about which check failed.
 */
class HubUsersApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('services.hub.users_api_key', '');
        if ($expected === '') {
            return response()->json(
                ['error' => 'users_api_key_not_configured'],
                Response::HTTP_UNAUTHORIZED,
            );
        }

        $header = (string) $request->header('Authorization', '');
        if (! str_starts_with($header, 'Bearer ')) {
            return $this->unauthorized();
        }

        $presented = substr($header, 7);
        if (! hash_equals($expected, $presented)) {
            return $this->unauthorized();
        }

        return $next($request);
    }

    private function unauthorized(): Response
    {
        return response()->json(
            ['error' => 'unauthorized'],
            Response::HTTP_UNAUTHORIZED,
            ['WWW-Authenticate' => 'Bearer'],
        );
    }
}
