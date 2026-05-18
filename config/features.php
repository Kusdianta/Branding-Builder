<?php

declare(strict_types=1);

/*
| Branding Builder feature flags.
|
| Surface area kept minimal on purpose — each entry must justify its
| existence by gating a feature the operator might want to toggle without
| a code deploy. Use env() defaults so prod stays on a single source of
| truth (the .env file) and tests can override via config()->set().
*/

return [
    /*
    | BB107 → BB113 — Step 3 TikTok input visibility.
    |
    | BB113 unblocks BB108: TikTokHandleChecker now uses the JSON
    | user/detail endpoint and reliably distinguishes real vs fake
    | handles. Default flipped to true so TikTok rejoins the audit
    | data flow as an availability-check-only signal worth +10 in
    | the Digital Presence pillar (no scrape, no penalty for absence).
    | Set WIZARD_SHOW_TIKTOK=false in .env to hide the input again.
    */
    'wizard_show_tiktok' => (bool) env('WIZARD_SHOW_TIKTOK', true),
];
