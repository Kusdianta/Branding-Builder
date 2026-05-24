<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SSO (spoke side)
|--------------------------------------------------------------------------
|
| branding-builder no longer talks to Google directly. Unauthenticated
| users are bounced to the Hub SSO gateway, which signs them in and hands
| back a short-lived signed token validated here with the SAME shared
| secret (SsoTokenValidator).
|
| SSO_SHARED_SECRET is a deploy-time secret (like APP_KEY) — identical in
| the Hub and every spoke, set in .env, NOT in the vault.
|
*/

return [

    'shared_secret' => env('SSO_SHARED_SECRET', ''),

    // Hub SSO entry point unauthenticated users are redirected to.
    'hub_sso_url' => env('HUB_SSO_URL', rtrim((string) env('HUB_URL', 'http://nema-hub.test'), '/') . '/auth/sso/redirect'),

    // This spoke's slug, sent to the Hub so it knows which callback host
    // allowlist to enforce.
    'spoke_slug' => env('SSO_SPOKE_SLUG', 'branding-builder'),

];
