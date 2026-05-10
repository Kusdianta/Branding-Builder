<?php

declare(strict_types=1);

namespace App\Services\Fetchers;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Fetches place details and reviews from the Google Places API (New).
 *
 * Limits:
 * - Max 5 reviews per call (Places API New hard limit).
 * - owner_response_rate is always 0.0 — Places API New does not expose owner
 *   replies on reviews. Google My Business API would be needed; out of scope v1.
 *
 * Caching: responses are cached by placeId for 24 h to respect API quota.
 * Retries: 429 responses trigger exponential back-off, up to 3 attempts.
 */
final class GoogleMapsReviewsFetcher
{
    private const PLACES_API_BASE  = 'https://places.googleapis.com/v1/places';
    private const FIELD_MASK       = 'id,displayName,rating,userRatingCount,reviews,websiteUri';
    private const CACHE_TTL        = 86400; // 24 h in seconds
    private const MAX_RETRIES      = 3;
    private const RETRY_BASE_MS    = 1000;

    public function __construct(private readonly string $apiKey) {}

    /**
     * @return array{
     *     rating: float,
     *     review_count: int,
     *     owner_response_rate: float,
     *     keyword_hits: array{positive: array<string,int>, negative: array<string,int>},
     *     recent_reviews: list<array{text: string, has_owner_response: bool}>,
     * }|null  null = place not found or unresolvable URL
     */
    public function fetch(string $gmapsUrl, string $brandName = ''): ?array
    {
        $placeId = $this->resolvePlaceId($gmapsUrl, $brandName);
        if ($placeId === null) {
            return null;
        }

        return Cache::remember(
            'gmaps_reviews:' . md5($placeId),
            self::CACHE_TTL,
            fn () => $this->callApi($placeId),
        );
    }

    // -------------------------------------------------------------------------
    // Place ID resolution
    // -------------------------------------------------------------------------

    private function resolvePlaceId(string $url, string $brandName): ?string
    {
        $placeId = $this->extractFromUrl($url);
        if ($placeId !== null) {
            return $placeId;
        }

        // Expand short links (maps.app.goo.gl / goo.gl)
        if (str_contains($url, 'goo.gl')) {
            $expanded = $this->expandShortUrl($url);
            if ($expanded !== null) {
                $placeId = $this->extractFromUrl($expanded);
                if ($placeId !== null) {
                    return $placeId;
                }
            }
        }

        // Last resort: text search by brand name
        if ($brandName !== '') {
            return $this->textSearch($brandName);
        }

        return null;
    }

    private function extractFromUrl(string $url): ?string
    {
        // data param: !1sChIJxxxxxxx
        if (preg_match('/!1s(ChIJ[A-Za-z0-9_-]+)/', $url, $m)) {
            return $m[1];
        }

        // ?cid=<numeric> — legacy CID, not a Place ID; skip
        return null;
    }

    private function expandShortUrl(string $shortUrl): ?string
    {
        try {
            $response = Http::withOptions(['allow_redirects' => false])
                ->timeout(5)
                ->get($shortUrl);

            $location = $response->header('Location');

            return ($location !== '' && $location !== null) ? $location : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function textSearch(string $brandName): ?string
    {
        try {
            $response = Http::withHeaders([
                'X-Goog-Api-Key'   => $this->apiKey,
                'X-Goog-FieldMask' => 'places.id',
            ])
            ->timeout(8)
            ->post('https://places.googleapis.com/v1/places:searchText', [
                'textQuery' => $brandName,
            ]);

            $places = $response->json('places', []);

            return ! empty($places) ? (string) ($places[0]['id'] ?? '') ?: null : null;
        } catch (\Throwable) {
            return null;
        }
    }

    // -------------------------------------------------------------------------
    // API call with retry
    // -------------------------------------------------------------------------

    private function callApi(string $placeId): ?array
    {
        $delay = self::RETRY_BASE_MS;

        for ($attempt = 0; $attempt < self::MAX_RETRIES; $attempt++) {
            try {
                $response = Http::withHeaders([
                    'X-Goog-Api-Key'   => $this->apiKey,
                    'X-Goog-FieldMask' => self::FIELD_MASK,
                ])
                ->timeout(8)
                ->get(self::PLACES_API_BASE . '/' . $placeId);

                if ($response->status() === 404) {
                    return null;
                }

                if ($response->status() === 429) {
                    if ($attempt + 1 < self::MAX_RETRIES) {
                        usleep($delay * 1000);
                        $delay *= 2;
                    }
                    continue;
                }

                if ($response->failed()) {
                    Log::warning('GoogleMapsReviewsFetcher: non-2xx response', [
                        'place_id' => $placeId,
                        'status'   => $response->status(),
                    ]);

                    return null;
                }

                return $this->parse($response->json());

            } catch (\Throwable $e) {
                Log::warning('GoogleMapsReviewsFetcher: request exception', [
                    'place_id' => $placeId,
                    'error'    => $e->getMessage(),
                ]);

                return null;
            }
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // Response parsing
    // -------------------------------------------------------------------------

    /** @param array<string,mixed> $data */
    private function parse(array $data): array
    {
        $rating      = (float) ($data['rating'] ?? 0.0);
        $reviewCount = (int) ($data['userRatingCount'] ?? 0);
        $rawReviews  = is_array($data['reviews'] ?? null) ? $data['reviews'] : [];

        $recentReviews = [];
        foreach ($rawReviews as $r) {
            $text = (string) ($r['text']['text'] ?? $r['originalText']['text'] ?? '');
            $recentReviews[] = [
                'text'               => $text,
                'has_owner_response' => false, // not available via Places API New
            ];
        }

        return [
            'rating'              => $rating,
            'review_count'        => $reviewCount,
            'owner_response_rate' => 0.0,
            'keyword_hits'        => $this->scanKeywords(
                array_column($recentReviews, 'text'),
            ),
            'recent_reviews'      => $recentReviews,
        ];
    }

    /**
     * Counts per-cluster phrase occurrences in the combined review text.
     * Clusters and phrases are read from config/branding.php — never hardcoded here.
     *
     * @param  list<string>  $texts
     * @return array{positive: array<string,int>, negative: array<string,int>}
     */
    private function scanKeywords(array $texts): array
    {
        /** @var array{positive:array<string,list<string>>,negative:array<string,list<string>>} $clusters */
        $clusters = config('branding.recall_keyword_clusters', []);
        $body     = mb_strtolower(implode(' ', $texts));

        $result = ['positive' => [], 'negative' => []];

        foreach (['positive', 'negative'] as $sentiment) {
            foreach ($clusters[$sentiment] ?? [] as $clusterName => $phrases) {
                $count = 0;
                foreach ($phrases as $phrase) {
                    $count += substr_count($body, mb_strtolower($phrase));
                }
                if ($count > 0) {
                    $result[$sentiment][$clusterName] = $count;
                }
            }
        }

        return $result;
    }
}
