<?php

declare(strict_types=1);

namespace App\View\Composers;

use Illuminate\View\View;

/**
 * BB89 — Inject Google Maps / Places API config into wizard views only.
 *
 * Scoped via View::composer in AppServiceProvider::boot() so the API key
 * never leaks into views that don't need it (admin pages, error pages,
 * /audits history listing, etc.). The key is referrer-restricted at the
 * GCP edge anyway, but minimising blast radius is cheap insurance.
 *
 * Exposes:
 *   $googleMapsApiKey       — string (may be empty in local-dev .env)
 *   $googleMapsCountryBias  — ISO 3166-1 alpha-2 (default 'id')
 *
 * Reads from services.google.maps_api_key / .maps_country_bias —
 * intentionally NOT a separate services.google_maps block, to keep the
 * Google config cluster (OAuth + Maps) in one place.
 */
final class MapsConfigComposer
{
    public function compose(View $view): void
    {
        $view->with([
            'googleMapsApiKey'      => (string) config('services.google.maps_api_key', ''),
            'googleMapsCountryBias' => (string) config('services.google.maps_country_bias', 'id'),
        ]);
    }
}
