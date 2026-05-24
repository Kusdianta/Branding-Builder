<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | Spoke-level credentials are loaded by VaultServiceProvider from the
    | shared vault file (vault/branding-builder.json) at boot. The env()
    | fallbacks below exist for local debugging when the vault is empty.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'anthropic' => [
        'key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-4-6'),
        // Separate from `model` so Naufal can upgrade the Phase 7-B IG analysis
        // call to e.g. claude-opus-4-7 via vault (_shared.json -> analysis.model)
        // without touching the deterministic / pillar-scorer model.
        'model_analysis' => env('ANTHROPIC_MODEL_ANALYSIS', env('ANTHROPIC_MODEL', 'claude-sonnet-4-6')),
    ],

    'google' => [
        'maps_api_key' => env('GOOGLE_MAPS_API_KEY'),

        // BB89 — Places Autocomplete country bias (ISO 3166-1 alpha-2).
        // Narrows the wizard's Step 1 search to a single country so the
        // results are dense and relevant. Indonesia by default. Read by
        // MapsConfigComposer and injected into wizard views only.
        'maps_country_bias' => env('GOOGLE_MAPS_COUNTRY_BIAS', 'id'),

        // Google OAuth moved to the Hub SSO gateway (SSO01). This spoke no
        // longer holds Google client credentials — auth is delegated to the
        // Hub. See config/sso.php.
    ],

    'nema_worker' => [
        'url'     => env('NEMA_WORKER_URL'),
        'api_key' => env('NEMA_WORKER_API_KEY'),
        'timeout' => (float) env('NEMA_WORKER_TIMEOUT', 10.0),
    ],

    // Hub internal API (Phase 7-B): fetch IG credentials, report status back.
    // Both keys are overridden by VaultServiceProvider when _shared.json carries
    // a `hub` block. Env values are local-dev fallbacks.
    'hub' => [
        'url'             => env('HUB_URL', 'http://nema-hub.test'),
        'inbound_api_key' => env('HUB_INBOUND_API_KEY', ''),
        'timeout'         => (float) env('HUB_TIMEOUT', 10.0),

        // BB84 — separate bearer used by Hub -> branding-builder traffic for
        // the users + credits-adjust API. Decoupled from inbound_api_key so
        // the two permissions can be rotated independently.
        'users_api_key'   => env('HUB_USERS_API_KEY', ''),
    ],

];
