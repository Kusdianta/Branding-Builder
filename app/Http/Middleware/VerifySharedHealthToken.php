<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the internal health endpoint with the shared worker API key
 * (vault/_shared.json -> worker.api_key, resolved into
 * services.nema_worker.api_key). Lets the Hub "Cek Sistem" probe this
 * spoke's platform health server-to-server without a web session, while
 * keeping the endpoint closed to the public. Constant-time comparison.
 */
class VerifySharedHealthToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('services.nema_worker.api_key');
        $provided = (string) $request->bearerToken();

        if ($expected === '' || ! hash_equals($expected, $provided)) {
            abort(401, 'Invalid health token.');
        }

        return $next($request);
    }
}
