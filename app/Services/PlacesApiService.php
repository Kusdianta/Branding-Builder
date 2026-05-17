<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * BB92 — Server-side Google Places API client for the wizard's manual
 * fallback path: paste a maps.app.goo.gl shortlink or full
 * google.com/maps URL and resolve it to a place_id + Place Details
 * payload.
 *
 * The primary autocomplete path runs entirely in the browser via the
 * places-autocomplete-js library (which handles session tokens for us).
 * This service is only used when the user clicks "Tidak ketemu? Tempel
 * link Google Maps" and pastes a URL.
 *
 * Cost notes:
 *   - Each manual resolution = 1 Text Search call (~$0.032) + 1 Place
 *     Details call (~$0.017) = ~$0.049 per fallback audit.
 *   - Autocomplete-driven submissions cost a single Place Details
 *     call at session-lock pricing (~$0.017).
 *   - Naufal monitors this via the places_api_calls log channel (BB97).
 *
 * The shape returned mirrors the wizard's selectPlace() handler input,
 * so the same Livewire method can hydrate place_* state regardless of
 * whether the data originated from autocomplete or manual URL paste.
 */
class PlacesApiService
{
    private const PLACES_BASE = 'https://maps.googleapis.com/maps/api/place';

    public function __construct(
        private readonly string $apiKey,
        private readonly float $timeoutSeconds = 10.0,
    ) {
    }

    public function hasApiKey(): bool
    {
        return $this->apiKey !== '';
    }

    /**
     * Resolve a pasted Google Maps URL to a Place Details payload.
     *
     * Supports:
     *   - https://maps.app.goo.gl/{shortcode}    (followed via redirect)
     *   - https://www.google.com/maps/place/...  (place name + lat/lng)
     *   - https://www.google.com/maps/?cid={CID} (numeric CID)
     *
     * Returns null when the URL cannot be parsed or no place is
     * found. Callers should surface a user-facing error pointing
     * the user back to the autocomplete input above.
     *
     * @return array<string,mixed>|null
     */
    public function resolveManualUrl(string $url): ?array
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }
        if (! $this->hasApiKey()) {
            Log::warning('PlacesApiService: API key missing, cannot resolve manual URL.', ['url' => $url]);
            return null;
        }

        try {
            $resolvedUrl = $this->followShortlink($url);
            if (! $resolvedUrl) {
                $this->logCall('shortlink_resolve_fail', ['url' => $url]);
                return null;
            }

            $anchor = $this->parseAnchor($resolvedUrl);
            if (! $anchor) {
                $this->logCall('anchor_parse_fail', ['url' => $resolvedUrl]);
                return null;
            }

            $placeId = $this->resolveToPlaceId($anchor);
            if (! $placeId) {
                $this->logCall('text_search_no_result', $anchor);
                return null;
            }

            return $this->fetchPlaceDetails($placeId);
        } catch (\Throwable $e) {
            Log::error('PlacesApiService: manual URL resolution exception', [
                'url'   => $url,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Fetch the canonical Place Details payload for a known place_id.
     *
     * @return array<string,mixed>|null
     */
    public function fetchPlaceDetails(string $placeId): ?array
    {
        if (! $this->hasApiKey() || $placeId === '') {
            return null;
        }
        try {
            $resp = Http::timeout($this->timeoutSeconds)
                ->get(self::PLACES_BASE . '/details/json', [
                    'place_id' => $placeId,
                    'fields'   => 'place_id,name,formatted_address,geometry,website,international_phone_number,types,rating,user_ratings_total,address_components,opening_hours,photos',
                    'key'      => $this->apiKey,
                    'language' => 'id',
                ]);
            $this->logCall('place_details', ['place_id' => $placeId, 'ok' => $resp->successful()]);

            if (! $resp->successful()) {
                return null;
            }
            $result = $resp->json('result');
            if (! is_array($result)) {
                return null;
            }
            return $this->normalizeDetailsResult($result);
        } catch (ConnectionException $e) {
            Log::warning('PlacesApiService: Place Details connection failed', [
                'place_id' => $placeId,
                'error'    => $e->getMessage(),
            ]);
            return null;
        }
    }

    /** Follow a maps.app.goo.gl shortlink; passthrough for non-shortlinks. */
    private function followShortlink(string $url): ?string
    {
        if (! preg_match('#^https?://maps\.app\.goo\.gl/#i', $url)) {
            return $url;
        }
        try {
            $response = Http::timeout($this->timeoutSeconds)
                ->withoutRedirecting()
                ->get($url);
            $location = $response->header('Location');
            return $location ?: null;
        } catch (ConnectionException $e) {
            Log::warning('PlacesApiService: shortlink fetch failed', ['url' => $url, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Extract a search anchor from a google.com/maps URL.
     *
     * @return array{cid?: string, lat?: float, lng?: float, query?: string}|null
     */
    private function parseAnchor(string $url): ?array
    {
        $anchor = [];

        if (preg_match('/[?&]cid=(\d+)/', $url, $m)) {
            $anchor['cid'] = $m[1];
        }

        if (preg_match('/@(-?\d+\.\d+),(-?\d+\.\d+)/', $url, $m)) {
            $anchor['lat'] = (float) $m[1];
            $anchor['lng'] = (float) $m[2];
        }

        if (preg_match('#/place/([^/]+)/#', $url, $m)) {
            $name = rawurldecode($m[1]);
            $name = str_replace('+', ' ', $name);
            $anchor['query'] = trim($name);
        }

        return $anchor !== [] ? $anchor : null;
    }

    /**
     * @param array<string,mixed> $anchor
     */
    private function resolveToPlaceId(array $anchor): ?string
    {
        $query    = isset($anchor['query']) && is_string($anchor['query']) ? $anchor['query'] : null;
        $location = isset($anchor['lat'], $anchor['lng']) ? "{$anchor['lat']},{$anchor['lng']}" : null;

        if ($query) {
            return $this->textSearchPlaceId($query, $location);
        }
        if ($location) {
            return $this->nearbyPlaceId($location);
        }
        return null;
    }

    private function textSearchPlaceId(string $query, ?string $location): ?string
    {
        $params = [
            'query'    => $query,
            'key'      => $this->apiKey,
            'language' => 'id',
            'region'   => 'id',
        ];
        if ($location) {
            $params['location'] = $location;
            $params['radius']   = 1500;
        }
        try {
            $resp = Http::timeout($this->timeoutSeconds)
                ->get(self::PLACES_BASE . '/textsearch/json', $params);
            $this->logCall('text_search', ['query' => $query, 'ok' => $resp->successful()]);
            if (! $resp->successful()) {
                return null;
            }
            return $resp->json('results.0.place_id');
        } catch (ConnectionException $e) {
            Log::warning('PlacesApiService: Text Search connection failed', ['query' => $query, 'error' => $e->getMessage()]);
            return null;
        }
    }

    private function nearbyPlaceId(string $location): ?string
    {
        try {
            $resp = Http::timeout($this->timeoutSeconds)
                ->get(self::PLACES_BASE . '/nearbysearch/json', [
                    'location' => $location,
                    'radius'   => 50,
                    'key'      => $this->apiKey,
                ]);
            $this->logCall('nearby_search', ['location' => $location, 'ok' => $resp->successful()]);
            if (! $resp->successful()) {
                return null;
            }
            return $resp->json('results.0.place_id');
        } catch (ConnectionException $e) {
            Log::warning('PlacesApiService: Nearby Search connection failed', ['location' => $location, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * @param array<string,mixed> $result
     * @return array<string,mixed>
     */
    private function normalizeDetailsResult(array $result): array
    {
        return [
            'place_id'                   => $result['place_id'] ?? null,
            'name'                       => $result['name'] ?? null,
            'formatted_address'          => $result['formatted_address'] ?? null,
            'geometry'                   => $result['geometry'] ?? null,
            'website'                    => $result['website'] ?? null,
            'international_phone_number' => $result['international_phone_number'] ?? null,
            'types'                      => $result['types'] ?? [],
            'rating'                     => $result['rating'] ?? null,
            'user_ratings_total'         => $result['user_ratings_total'] ?? null,
            'address_components'         => $result['address_components'] ?? [],
            'opening_hours'              => $result['opening_hours'] ?? null,
            'raw'                        => $result,
        ];
    }

    /**
     * BB92 — every Places API call routes through Log::info with the
     * 'places_api:' prefix so cost-attribution greps work today even
     * without a dedicated channel. BB97 promotes this to its own
     * 'places-api' log channel + daily rotation for production.
     *
     * @param array<string,mixed> $context
     */
    private function logCall(string $kind, array $context): void
    {
        Log::info('places_api:' . $kind, $context + ['ts' => now()->toIso8601String()]);
    }
}
