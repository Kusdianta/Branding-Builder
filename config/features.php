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
    | BB107 — Step 3 TikTok input visibility.
    |
    | When false (default), the wizard hides the TikTok handle field
    | entirely. The /check-handle/tiktok route, TikTokHandleChecker
    | service, and downstream scoring services (DigitalPresenceScorer,
    | KonsistensiScorer) all stay live — only the wizard's INPUT
    | disappears. Flip via WIZARD_SHOW_TIKTOK=true in .env.
    |
    | Blocked on: BB108 — TikTokHandleChecker rewrite. Same fixture-rot
    | problem as the pre-BB107 IG checker (anonymous TT HTML scraping
    | no longer reliably distinguishes real vs fake handles).
    */
    'wizard_show_tiktok' => (bool) env('WIZARD_SHOW_TIKTOK', false),
];
