<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * BB05 — best-effort notifier that tells the Hub to log a user out
 * platform-wide. Called when a user signs out of branding-builder; the
 * Hub then clears its own session and broadcasts to the other spokes
 * (SSO06). Fire-and-forget: a Hub outage must never block local logout.
 */
class HubSsoClient
{
    public function notifyLogout(string $hubUserId): void
    {
        if ($hubUserId === '') {
            return;
        }

        $base = rtrim((string) config('services.hub.url', ''), '/');
        if ($base === '') {
            return;
        }

        try {
            Http::withToken((string) config('services.hub.inbound_api_key', ''))
                ->acceptJson()
                ->timeout(5)
                ->post($base . '/auth/sso/logout', ['hub_user_id' => $hubUserId]);
        } catch (Throwable $e) {
            Log::info('[branding-builder] Hub logout notify failed (best-effort)', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
